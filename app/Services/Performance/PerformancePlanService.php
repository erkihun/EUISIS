<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Enums\AuditEventType;
use App\Enums\Performance\KpiAggregation;
use App\Enums\Performance\KpiDirection;
use App\Enums\Performance\ObjectiveType;
use App\Enums\Performance\PlanStatus;
use App\Enums\Performance\PlanType;
use App\Models\Kpi;
use App\Models\KpiTarget;
use App\Models\OrganizationUnit;
use App\Models\PerformanceCascade;
use App\Models\PerformanceCycle;
use App\Models\PerformanceObjective;
use App\Models\PerformancePlan;
use App\Models\Position;
use App\Models\StrategicGoal;
use App\Models\User;
use App\Services\Performance\Calculation\Dec;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Organization, unit and position plans (docs/epms-cascade-rules.md).
 *
 * Structure follows the real organization-unit tree, to any depth: a unit
 * plan's parent is the plan of its nearest planned ancestor (or the
 * organization plan); a position plan's parent is its unit's plan.
 *
 * Only a DRAFT plan is editable. A published plan is changed by creating a
 * new version; agreements and results stay linked to the version they used.
 */
final class PerformancePlanService
{
    public function __construct(private readonly EpmsAccess $access, private readonly EpmsAudit $audit, private readonly StrategicPlanningService $strategicPlanning) {}

    public static function isEditable(PerformancePlan $plan): bool
    {
        return $plan->status === PlanStatus::Draft && ! PerformanceCycleService::isReadOnly($plan->cycle);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): PerformancePlan
    {
        $type = PlanType::from($data['plan_type']);
        $this->access->authorize($this->access->inScope($actor, 'performance_plans.create', $data['organization_id'] ?? null));

        /** @var PerformanceCycle $cycle */
        $cycle = PerformanceCycle::query()->findOrFail($data['cycle_id']);
        if (PerformanceCycleService::isReadOnly($cycle)) {
            throw ValidationException::withMessages(['cycle_id' => __('performance.errors.cycle_read_only')]);
        }
        if ($cycle->organization_id !== null && $cycle->organization_id !== $data['organization_id']) {
            throw ValidationException::withMessages(['cycle_id' => __('performance.errors.cycle_other_organization')]);
        }

        $unitId = $data['organization_unit_id'] ?? null;
        $positionId = $data['position_id'] ?? null;

        if ($type === PlanType::Organization) {
            $unitId = $positionId = null;
        }
        if ($type === PlanType::Position) {
            $position = Position::query()->find($positionId);
            if ($position === null || $position->organization_id !== $data['organization_id']) {
                throw ValidationException::withMessages(['position_id' => __('performance.errors.position_outside_organization')]);
            }
            $unitId = $position->organization_unit_id;
        }
        if ($type === PlanType::Unit) {
            $positionId = null;
            if ($unitId === null || ! OrganizationUnit::query()->whereKey($unitId)->where('organization_id', $data['organization_id'])->exists()) {
                throw ValidationException::withMessages(['organization_unit_id' => __('performance.errors.unit_outside_organization')]);
            }
        }

        $parent = $this->resolveParent($type, $cycle->getKey(), $data['organization_id'], $unitId, $data['parent_plan_id'] ?? null);

        $duplicate = PerformancePlan::query()
            ->where('cycle_id', $cycle->getKey())->where('plan_type', $type->value)
            ->where('organization_id', $data['organization_id'])
            ->where('organization_unit_id', $unitId)->where('position_id', $positionId)
            ->whereNotIn('status', [PlanStatus::Superseded->value, PlanStatus::Closed->value])
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['plan_type' => __('performance.errors.plan_exists')]);
        }

        $plan = new PerformancePlan([
            'cycle_id' => $cycle->getKey(),
            'plan_type' => $type,
            'organization_id' => $data['organization_id'],
            'organization_unit_id' => $unitId,
            'position_id' => $positionId,
            'parent_plan_id' => $parent?->getKey(),
            'title' => $data['title'],
            'effective_from' => $data['effective_from'] ?? $cycle->start_date,
            'effective_to' => $data['effective_to'] ?? $cycle->end_date,
        ]);
        $plan->forceFill(['lineage_key' => (string) Str::uuid7(), 'version_no' => 1, 'status' => PlanStatus::Draft, 'created_by' => $actor->getKey()])->save();

        $this->audit->record(AuditEventType::PerformancePlanCreated, $actor, $plan, $plan->only(['plan_type', 'cycle_id', 'organization_unit_id', 'position_id', 'parent_plan_id']));

        return $plan;
    }

    /**
     * The parent plan must be the published plan one level up in the real
     * structure: organization → nearest planned ancestor unit → position.
     */
    private function resolveParent(PlanType $type, string $cycleId, string $organizationId, ?string $unitId, ?string $requestedParentId): ?PerformancePlan
    {
        if ($type === PlanType::Organization) {
            return null;
        }

        $parent = $requestedParentId !== null ? PerformancePlan::query()->find($requestedParentId) : null;
        if ($parent === null) {
            throw ValidationException::withMessages(['parent_plan_id' => __('performance.errors.parent_required')]);
        }
        if ($parent->status !== PlanStatus::Published || $parent->cycle_id !== $cycleId || $parent->organization_id !== $organizationId) {
            throw ValidationException::withMessages(['parent_plan_id' => __('performance.errors.parent_not_published')]);
        }

        if ($type === PlanType::Position) {
            $valid = $parent->plan_type === PlanType::Unit && $parent->organization_unit_id === $unitId
                || $unitId === null && $parent->plan_type === PlanType::Organization;
        } else {
            $ancestors = $this->unitAncestors($unitId);
            $valid = $parent->plan_type === PlanType::Organization
                || $parent->plan_type === PlanType::Unit && in_array($parent->organization_unit_id, $ancestors, true);
        }

        if (! $valid) {
            throw ValidationException::withMessages(['parent_plan_id' => __('performance.errors.parent_structure')]);
        }

        return $parent;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function periodWithinCycle(PerformancePlan $plan, ?string $from, ?string $to): array
    {
        $cycle = $plan->cycle;
        $start = Carbon::parse($from ?? $cycle->start_date);
        $end = Carbon::parse($to ?? $cycle->end_date);
        if ($start->lt($cycle->start_date) || $end->gt($cycle->end_date) || $end->lt($start)) {
            throw ValidationException::withMessages(['period_start' => __('performance.errors.period_outside_cycle')]);
        }

        return [$start, $end];
    }

    private function assertParentTarget(PerformancePlan $plan, string $kpiId, ?string $parentTargetId): void
    {
        if ($parentTargetId === null || $parentTargetId === '') {
            return;
        }
        $parentTarget = KpiTarget::query()->find($parentTargetId);
        if ($parentTarget === null || $parentTarget->performance_plan_id !== $plan->parent_plan_id || $parentTarget->kpi_id !== $kpiId) {
            throw ValidationException::withMessages(['parent_target_id' => __('performance.errors.parent_target_invalid')]);
        }
    }

    /** @return list<string> ancestor unit ids, nearest first (cycle-safe) */
    private function unitAncestors(?string $unitId): array
    {
        $ancestors = [];
        $current = $unitId !== null ? OrganizationUnit::query()->find($unitId) : null;
        while ($current !== null && $current->parent_unit_id !== null && ! in_array($current->parent_unit_id, $ancestors, true)) {
            $ancestors[] = $current->parent_unit_id;
            $current = OrganizationUnit::query()->find($current->parent_unit_id);
        }

        return $ancestors;
    }

    // ── Objectives ───────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    public function addObjective(PerformancePlan $plan, array $data, User $actor): PerformanceObjective
    {
        $this->assertEditable($plan, $actor);
        $this->assertStrategicGoal($plan, $data['strategic_goal_id'] ?? null);

        if ($plan->objectives()->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => __('performance.errors.code_taken')]);
        }

        $objective = $plan->objectives()->create([
            ...$data,
            // An empty priority means "no priority": the column's default, not NULL.
            'priority' => $data['priority'] ?? 0,
            'objective_type' => $data['objective_type'] ?? ObjectiveType::Local->value,
            'cascade_mode' => 'LOCAL_ONLY',
            'parent_objective_id' => null,
        ]);

        $this->audit->record(AuditEventType::PerformanceObjectiveChanged, $actor, $plan, ['added_objective' => $objective->code, 'weight' => $objective->weight]);

        return $objective;
    }

    /** @param array<string, mixed> $data */
    public function updateObjective(PerformanceObjective $objective, array $data, User $actor): PerformanceObjective
    {
        $this->assertEditable($objective->plan, $actor);
        if (array_key_exists('strategic_goal_id', $data)) {
            $this->assertStrategicGoal($objective->plan, $data['strategic_goal_id']);
        }
        $old = $objective->only(array_keys($data));
        // Lineage and inherited obligations are not editable here.
        unset($data['parent_objective_id'], $data['source_objective_id'], $data['is_mandatory'], $data['cascade_mode']);
        if (array_key_exists('priority', $data)) {
            $data['priority'] ??= 0;
        }
        $objective->fill($data)->save();
        $this->audit->record(AuditEventType::PerformanceObjectiveChanged, $actor, $objective->plan, ['objective' => $objective->code, ...$data], $old);

        return $objective;
    }

    public function removeObjective(PerformanceObjective $objective, User $actor): void
    {
        $this->assertEditable($objective->plan, $actor);
        if ($objective->parent_objective_id !== null && $objective->parentObjective?->is_mandatory) {
            throw ValidationException::withMessages(['objective' => __('performance.errors.mandatory_cannot_be_removed')]);
        }
        $this->audit->record(AuditEventType::PerformanceObjectiveChanged, $actor, $objective->plan, ['removed_objective' => $objective->code]);
        $objective->delete();
    }

    // ── Targets ──────────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    public function addTarget(PerformanceObjective $objective, array $data, User $actor): KpiTarget
    {
        $plan = $objective->plan;
        $this->assertEditable($plan, $actor);

        /** @var Kpi $kpi */
        $kpi = Kpi::query()->where('is_active', true)->findOrFail($data['kpi_id']);
        if ($kpi->organization_id !== null && $kpi->organization_id !== $plan->organization_id) {
            throw ValidationException::withMessages(['kpi_id' => __('performance.errors.kpi_other_organization')]);
        }

        [$start, $end] = $this->periodWithinCycle($plan, $data['period_start'] ?? null, $data['period_end'] ?? null);
        $this->assertParentTarget($plan, $kpi->getKey(), $data['parent_target_id'] ?? null);

        $target = $objective->targets()->create([
            ...$data,
            'kpi_id' => $kpi->getKey(),
            'performance_plan_id' => $plan->getKey(),
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'period_type' => $data['period_type'] ?? $kpi->frequency->value,
        ]);

        $this->audit->record(AuditEventType::KpiTargetChanged, $actor, $plan, ['added_target' => $kpi->code, 'target_value' => $target->target_value, 'weight' => $target->weight]);

        return $target;
    }

    /** @param array<string, mixed> $data */
    public function updateTarget(KpiTarget $target, array $data, User $actor): KpiTarget
    {
        $this->assertEditable($target->plan, $actor);
        unset($data['kpi_id'], $data['performance_plan_id'], $data['objective_id'], $data['amended_from_id'], $data['version_no'], $data['is_current']);
        // A cleared period type keeps the current one (the column has no "none").
        if (array_key_exists('period_type', $data) && $data['period_type'] === null) {
            unset($data['period_type']);
        }

        // Same rules as addTarget(): the period stays inside the cycle and a
        // contribution link may only point at the parent plan's target for the same KPI.
        [$start, $end] = $this->periodWithinCycle($target->plan, $data['period_start'] ?? $target->period_start->toDateString(), $data['period_end'] ?? $target->period_end->toDateString());
        $data['period_start'] = $start->toDateString();
        $data['period_end'] = $end->toDateString();
        if (array_key_exists('parent_target_id', $data)) {
            $this->assertParentTarget($target->plan, $target->kpi_id, $data['parent_target_id']);
        }
        $old = $target->only(array_keys($data));
        $target->fill($data)->save();
        $this->audit->record(AuditEventType::KpiTargetChanged, $actor, $target->plan, ['target' => $target->getKey(), ...$data], $old);

        return $target;
    }

    public function removeTarget(KpiTarget $target, User $actor): void
    {
        $this->assertEditable($target->plan, $actor);
        $this->audit->record(AuditEventType::KpiTargetChanged, $actor, $target->plan, ['removed_target' => $target->getKey()]);
        $target->delete();
    }

    // ── Validation & workflow ────────────────────────────────────────────

    /** @return list<string> human-readable problems; empty = publishable */
    public function validate(PerformancePlan $plan): array
    {
        $errors = [];
        if ($plan->plan_type === PlanType::Organization && StrategicGoal::query()->where('cycle_id', $plan->cycle_id)->where('organization_id', $plan->organization_id)->exists()) {
            $errors = [...$errors, ...$this->strategicPlanning->organizationReadiness($plan->cycle_id, $plan->organization_id)['problems']];
        }
        $objectives = $plan->objectives()->with(['targets.kpi'])->get();
        $active = $objectives->where('status', 'ACTIVE');

        if ($active->isEmpty()) {
            $errors[] = __('performance.validation.no_objectives');
        }

        $objectiveWeight = Dec::sum($active->map(fn ($o) => Dec::of($o->weight)));
        if ($active->isNotEmpty() && ! $objectiveWeight->isEqualTo(100)) {
            $errors[] = __('performance.validation.objective_weights', ['total' => Dec::str($objectiveWeight, 2)]);
        }

        foreach ($active as $objective) {
            $targets = $objective->targets;
            if ($targets->isEmpty()) {
                $errors[] = __('performance.validation.objective_without_target', ['code' => $objective->code]);

                continue;
            }
            $weight = Dec::sum($targets->map(fn ($t) => Dec::of($t->weight)));
            if (! $weight->isEqualTo(100)) {
                $errors[] = __('performance.validation.target_weights', ['code' => $objective->code, 'total' => Dec::str($weight, 2)]);
            }
            foreach ($targets as $target) {
                $problem = $this->targetProblem($target);
                if ($problem !== null) {
                    $errors[] = __('performance.validation.target_invalid', ['code' => $objective->code, 'kpi' => $target->kpi?->code, 'problem' => $problem]);
                }
            }
        }

        if ($plan->plan_type !== PlanType::Organization) {
            $parent = $plan->parentPlan;
            if ($parent === null || $parent->status !== PlanStatus::Published) {
                $errors[] = __('performance.validation.parent_not_published');
            } else {
                foreach ($parent->objectives()->where('is_mandatory', true)->where('status', 'ACTIVE')->get() as $mandatory) {
                    $covered = $active->contains(fn ($o) => $o->parent_objective_id === $mandatory->getKey());
                    if (! $covered) {
                        $errors[] = __('performance.validation.mandatory_not_cascaded', ['code' => $mandatory->code]);
                    }
                }
            }
            if ($this->hasCircularParent($plan)) {
                $errors[] = __('performance.validation.circular');
            }
        }

        if ($plan->effective_from !== null && $plan->effective_to !== null && $plan->effective_to->lt($plan->effective_from)) {
            $errors[] = __('performance.validation.effective_dates');
        }

        return $errors;
    }

    private function assertStrategicGoal(PerformancePlan $plan, ?string $goalId): void
    {
        if ($goalId === null || $goalId === '') {
            return;
        }
        $valid = StrategicGoal::query()->whereKey($goalId)->where('cycle_id', $plan->cycle_id)
            ->where('organization_id', $plan->organization_id)->exists();
        if (! $valid) {
            throw ValidationException::withMessages(['strategic_goal_id' => __('performance.validation.goal_outside_plan')]);
        }
    }

    private function targetProblem(KpiTarget $target): ?string
    {
        $kpi = $target->kpi;
        if ($kpi === null || ! $kpi->is_active) {
            return __('performance.validation.kpi_inactive');
        }

        $hasValue = $target->target_value !== null
            || ($target->target_numerator !== null && $target->target_denominator !== null && ! Dec::of($target->target_denominator)->isZero());

        return match ($kpi->direction) {
            KpiDirection::HigherIsBetter => $hasValue && (Dec::of($target->target_value)?->isPositive() ?? true) ? null : __('performance.validation.target_positive'),
            KpiDirection::LowerIsBetter => $hasValue ? null : __('performance.validation.target_missing'),
            KpiDirection::TargetIsBest => ! $hasValue ? __('performance.validation.target_missing')
                : ((Dec::of($target->target_value)?->isZero() ?? false) && $target->zero_score_deviation === null && $kpi->zero_score_deviation === null
                    ? __('performance.validation.zero_score_deviation') : null),
            KpiDirection::Milestone => empty($kpi->milestones) ? __('performance.validation.milestones_missing') : null,
            KpiDirection::Binary => null,
        } ?? ($kpi->aggregation_method === KpiAggregation::RatioFromTotals && $target->target_value === null && $target->target_numerator === null
            ? __('performance.validation.target_missing') : null);
    }

    private function hasCircularParent(PerformancePlan $plan): bool
    {
        $seen = [$plan->getKey()];
        $current = $plan->parentPlan;
        while ($current !== null) {
            if (in_array($current->getKey(), $seen, true)) {
                return true;
            }
            $seen[] = $current->getKey();
            $current = $current->parentPlan;
        }

        return false;
    }

    public function submit(PerformancePlan $plan, User $actor): PerformancePlan
    {
        $this->access->authorize($this->access->inScope($actor, 'performance_plans.update', $plan->organization_id));
        $this->assertStatus($plan, [PlanStatus::Draft]);
        $errors = $this->validate($plan);
        if ($errors !== []) {
            throw ValidationException::withMessages(['plan' => $errors]);
        }

        return $this->move($plan, PlanStatus::UnderReview, $actor, ['submitted_by' => $actor->getKey(), 'submitted_at' => now(), 'return_reason' => null]);
    }

    public function returnToDraft(PerformancePlan $plan, string $reason, User $actor): PerformancePlan
    {
        $this->access->authorize($this->access->inScope($actor, 'performance_plans.review', $plan->organization_id));
        $this->assertStatus($plan, [PlanStatus::UnderReview, PlanStatus::Approved]);

        return $this->move($plan, PlanStatus::Draft, $actor, ['return_reason' => $reason, 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now()], $reason);
    }

    public function approve(PerformancePlan $plan, User $actor): PerformancePlan
    {
        $this->access->authorize($this->access->inScope($actor, 'performance_plans.approve', $plan->organization_id));
        $this->assertStatus($plan, [PlanStatus::UnderReview]);
        $this->access->assertSeparated($actor, $plan->submitted_by, 'approve');

        return $this->move($plan, PlanStatus::Approved, $actor, ['approved_by' => $actor->getKey(), 'approved_at' => now(), 'reviewed_by' => $plan->reviewed_by ?? $actor->getKey(), 'reviewed_at' => $plan->reviewed_at ?? now()]);
    }

    public function publish(PerformancePlan $plan, User $actor): PerformancePlan
    {
        $this->access->authorize($this->access->inScope($actor, 'performance_plans.publish', $plan->organization_id));
        $this->assertStatus($plan, [PlanStatus::Approved]);
        $errors = $this->validate($plan);
        if ($errors !== []) {
            throw ValidationException::withMessages(['plan' => $errors]);
        }

        return DB::transaction(function () use ($plan, $actor): PerformancePlan {
            if ($plan->supersedes_plan_id !== null) {
                $previous = PerformancePlan::query()->whereKey($plan->supersedes_plan_id)->lockForUpdate()->first();
                if ($previous !== null && $previous->status === PlanStatus::Published) {
                    $previous->forceFill(['status' => PlanStatus::Superseded, 'live_key' => null, 'effective_to' => now()->subDay()->max($previous->effective_from ?? now()->subDay())])->save();
                    $this->audit->record(AuditEventType::PerformancePlanStatusChanged, $actor, $previous, ['status' => PlanStatus::Superseded->value], ['status' => PlanStatus::Published->value]);
                }
            }

            return $this->move($plan, PlanStatus::Published, $actor, [
                'published_by' => $actor->getKey(),
                'published_at' => now(),
                'live_key' => $plan->lineage_key,
            ]);
        });
    }

    /**
     * Change a published plan: a new DRAFT version copies objectives, targets
     * and cascade lineage (source_objective_id points back). The old version
     * stays PUBLISHED — and in use — until the new one is published.
     */
    public function newVersion(PerformancePlan $plan, string $reason, User $actor): PerformancePlan
    {
        $this->access->authorize($this->access->inScope($actor, 'performance_plans.update', $plan->organization_id));
        $this->assertStatus($plan, [PlanStatus::Published]);

        $pending = PerformancePlan::query()->where('lineage_key', $plan->lineage_key)
            ->whereIn('status', [PlanStatus::Draft->value, PlanStatus::UnderReview->value, PlanStatus::Approved->value])->exists();
        if ($pending) {
            throw ValidationException::withMessages(['plan' => __('performance.errors.version_pending')]);
        }

        return DB::transaction(function () use ($plan, $reason, $actor): PerformancePlan {
            $next = $plan->replicate(['status', 'live_key', 'submitted_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'approved_by', 'approved_at', 'published_by', 'published_at', 'return_reason']);
            $next->forceFill([
                'version_no' => (int) PerformancePlan::query()->where('lineage_key', $plan->lineage_key)->max('version_no') + 1,
                'supersedes_plan_id' => $plan->getKey(),
                'status' => PlanStatus::Draft,
                'change_reason' => $reason,
                'created_by' => $actor->getKey(),
            ])->save();

            $objectiveMap = [];
            foreach ($plan->objectives()->get() as $objective) {
                $copy = $objective->replicate();
                $copy->forceFill(['performance_plan_id' => $next->getKey(), 'source_objective_id' => $objective->getKey()])->save();
                $objectiveMap[$objective->getKey()] = $copy->getKey();

                foreach ($objective->targets()->get() as $target) {
                    $targetCopy = $target->replicate();
                    $targetCopy->forceFill(['performance_plan_id' => $next->getKey(), 'objective_id' => $copy->getKey()])->save();
                }
            }

            foreach (PerformanceCascade::query()->where('child_plan_id', $plan->getKey())->get() as $cascade) {
                if (isset($objectiveMap[$cascade->child_objective_id])) {
                    $row = $cascade->replicate();
                    $row->forceFill(['child_plan_id' => $next->getKey(), 'child_objective_id' => $objectiveMap[$cascade->child_objective_id], 'created_by' => $actor->getKey(), 'created_at' => now()])->save();
                }
            }

            $this->audit->record(AuditEventType::PerformancePlanVersioned, $actor, $next, ['version_no' => $next->version_no, 'supersedes' => $plan->getKey()], null, $reason);

            return $next;
        });
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public function assertEditable(PerformancePlan $plan, User $actor): void
    {
        $this->access->authorize($this->access->inScope($actor, 'performance_objectives.manage', $plan->organization_id)
            || $this->access->inScope($actor, 'performance_plans.update', $plan->organization_id));
        if (! self::isEditable($plan)) {
            throw ValidationException::withMessages(['plan' => __('performance.errors.plan_locked')]);
        }
    }

    /** @param list<PlanStatus> $allowed */
    private function assertStatus(PerformancePlan $plan, array $allowed): void
    {
        if (! in_array($plan->status, $allowed, true) || PerformanceCycleService::isReadOnly($plan->cycle)) {
            throw ValidationException::withMessages(['status' => __('performance.errors.invalid_plan_status', ['status' => $plan->status->value])]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function move(PerformancePlan $plan, PlanStatus $to, User $actor, array $attributes = [], ?string $reason = null): PerformancePlan
    {
        $from = $plan->status;
        $plan->forceFill(['status' => $to, ...$attributes])->save();
        $this->audit->record(AuditEventType::PerformancePlanStatusChanged, $actor, $plan, ['status' => $to->value], ['status' => $from->value], $reason);

        return $plan;
    }
}
