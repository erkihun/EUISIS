<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Enums\AuditEventType;
use App\Enums\Performance\StrategicGoalStatus;
use App\Models\KpiPeriodTarget;
use App\Models\KpiTarget;
use App\Models\OrganizationUnit;
use App\Models\PerformanceCycle;
use App\Models\StrategicGoal;
use App\Models\StrategicGoalAllocation;
use App\Models\User;
use App\Services\Performance\Calculation\Dec;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StrategicPlanningService
{
    public function __construct(private readonly EpmsAccess $access, private readonly EpmsAudit $audit) {}

    /** @param array<string, mixed> $data */
    public function createGoal(array $data, User $actor): StrategicGoal
    {
        $this->access->authorize($this->access->inScope($actor, 'strategic_goals.create', $data['organization_id']));
        $cycle = PerformanceCycle::query()->findOrFail($data['cycle_id']);
        if (PerformanceCycleService::isReadOnly($cycle)) {
            throw ValidationException::withMessages(['cycle_id' => __('performance.errors.cycle_read_only')]);
        }
        if ($cycle->organization_id !== null && $cycle->organization_id !== $data['organization_id']) {
            throw ValidationException::withMessages(['cycle_id' => __('performance.errors.cycle_other_organization')]);
        }
        $this->assertDatesInsideCycle($cycle, $data['effective_from'] ?? null, $data['effective_to'] ?? null);

        return DB::transaction(function () use ($data, $actor, $cycle): StrategicGoal {
            if (StrategicGoal::query()->where('cycle_id', $cycle->getKey())->where('organization_id', $data['organization_id'])->where('code', $data['code'])->exists()) {
                throw ValidationException::withMessages(['code' => __('performance.errors.code_taken')]);
            }
            $goal = new StrategicGoal([
                ...$data,
                'effective_from' => $data['effective_from'] ?? $cycle->start_date,
                'effective_to' => $data['effective_to'] ?? $cycle->end_date,
            ]);
            $goal->forceFill(['status' => StrategicGoalStatus::Draft, 'created_by' => $actor->getKey()])->save();
            $this->audit->record(AuditEventType::StrategicGoalCreated, $actor, $goal, $goal->toArray());

            return $goal;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateGoal(StrategicGoal $goal, array $data, User $actor): StrategicGoal
    {
        $this->assertDraft($goal, $actor, 'strategic_goals.update');
        $this->assertDatesInsideCycle($goal->cycle, $data['effective_from'] ?? $goal->effective_from?->toDateString(), $data['effective_to'] ?? $goal->effective_to?->toDateString());
        if (isset($data['code']) && StrategicGoal::query()->where('cycle_id', $goal->cycle_id)->where('organization_id', $goal->organization_id)->where('code', $data['code'])->where('id', '!=', $goal->getKey())->exists()) {
            throw ValidationException::withMessages(['code' => __('performance.errors.code_taken')]);
        }
        $old = $goal->only(array_keys($data));
        $goal->fill($data)->save();
        $this->audit->record(AuditEventType::StrategicGoalChanged, $actor, $goal, $goal->only(array_keys($data)), $old);

        return $goal;
    }

    public function deleteGoal(StrategicGoal $goal, User $actor): void
    {
        $this->assertDraft($goal, $actor, 'strategic_goals.delete_draft');
        if ($goal->objectives()->exists()) {
            throw ValidationException::withMessages(['goal' => __('performance.validation.goal_has_objectives')]);
        }
        $this->audit->record(AuditEventType::StrategicGoalChanged, $actor, $goal, ['deleted' => true]);
        $goal->delete();
    }

    /** @param array<string, mixed> $data */
    public function addAllocation(StrategicGoal $goal, array $data, User $actor): StrategicGoalAllocation
    {
        $this->assertDraft($goal, $actor, 'strategic_goal_allocations.manage');
        $unit = OrganizationUnit::query()->findOrFail($data['organization_unit_id']);
        if ($unit->organization_id !== $goal->organization_id) {
            throw ValidationException::withMessages(['organization_unit_id' => __('performance.errors.unit_outside_organization')]);
        }

        return DB::transaction(function () use ($goal, $data, $actor): StrategicGoalAllocation {
            StrategicGoal::query()->whereKey($goal->getKey())->lockForUpdate()->firstOrFail();
            if ($goal->allocations()->where('organization_unit_id', $data['organization_unit_id'])->exists()) {
                throw ValidationException::withMessages(['organization_unit_id' => __('performance.validation.allocation_duplicate')]);
            }
            if (($data['is_lead'] ?? false) && $goal->allocations()->where('is_lead', true)->exists()) {
                throw ValidationException::withMessages(['is_lead' => __('performance.validation.lead_exists')]);
            }
            $allocation = $goal->allocations()->create([...$data, 'created_by' => $actor->getKey()]);
            $this->audit->record(AuditEventType::StrategicGoalAllocationChanged, $actor, $goal, ['added' => $allocation->toArray()]);

            return $allocation;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateAllocation(StrategicGoalAllocation $allocation, array $data, User $actor): StrategicGoalAllocation
    {
        $goal = $allocation->goal;
        $this->assertDraft($goal, $actor, 'strategic_goal_allocations.manage');
        if (($data['is_lead'] ?? false) && $goal->allocations()->where('id', '!=', $allocation->getKey())->where('is_lead', true)->exists()) {
            throw ValidationException::withMessages(['is_lead' => __('performance.validation.lead_exists')]);
        }
        $old = $allocation->only(array_keys($data));
        unset($data['organization_unit_id']);
        $allocation->fill($data)->save();
        $this->audit->record(AuditEventType::StrategicGoalAllocationChanged, $actor, $goal, ['allocation' => $allocation->getKey(), ...$data], $old);

        return $allocation;
    }

    public function deleteAllocation(StrategicGoalAllocation $allocation, User $actor): void
    {
        $goal = $allocation->goal;
        $this->assertDraft($goal, $actor, 'strategic_goal_allocations.manage');
        $this->audit->record(AuditEventType::StrategicGoalAllocationChanged, $actor, $goal, ['removed' => $allocation->getKey()]);
        $allocation->delete();
    }

    /** @return array{allocation_total:string,objective_total:string,remaining:string,ready:bool,problems:list<string>} */
    public function readiness(StrategicGoal $goal): array
    {
        $allocation = Dec::sum($goal->allocations()->get()->map(fn ($row) => Dec::of($row->organization_contribution_percent)));
        $objective = Dec::sum($goal->objectives()->where('status', 'ACTIVE')
            ->whereHas('plan', fn ($query) => $query->whereNotIn('status', ['SUPERSEDED', 'CLOSED']))
            ->get()->map(fn ($row) => Dec::of($row->absolute_weight_percent ?? $row->weight)));
        $weight = Dec::of($goal->weight_percent);
        $problems = [];
        if (! $allocation->isEqualTo($weight)) {
            $problems[] = __('performance.validation.allocation_total', ['total' => Dec::str($allocation, 2), 'weight' => Dec::str($weight, 2)]);
        }
        if ($goal->allocations()->where('is_lead', true)->count() !== 1) {
            $problems[] = __('performance.validation.one_lead');
        }
        if (! $objective->isEqualTo($weight)) {
            $problems[] = __('performance.validation.goal_objective_total', ['total' => Dec::str($objective, 2), 'weight' => Dec::str($weight, 2)]);
        }

        return [
            'allocation_total' => Dec::str($allocation),
            'objective_total' => Dec::str($objective),
            'remaining' => Dec::str($weight->minus($allocation)),
            'ready' => $problems === [],
            'problems' => $problems,
        ];
    }

    /** @return array{total:string,remaining:string,ready:bool,problems:list<string>} */
    public function organizationReadiness(string $cycleId, string $organizationId): array
    {
        $goals = StrategicGoal::query()->where('cycle_id', $cycleId)->where('organization_id', $organizationId)
            ->where('status', '!=', StrategicGoalStatus::Superseded->value)->get();
        $total = Dec::sum($goals->map(fn ($goal) => Dec::of($goal->weight_percent)));
        $problems = [];
        if ($goals->isEmpty()) {
            $problems[] = __('performance.validation.no_strategic_goals');
        } elseif (! $total->isEqualTo(100)) {
            $problems[] = __('performance.validation.goal_weights', ['total' => Dec::str($total, 2)]);
        }
        foreach ($goals as $goal) {
            foreach ($this->readiness($goal)['problems'] as $problem) {
                $problems[] = $goal->code.': '.$problem;
            }
        }

        return ['total' => Dec::str($total), 'remaining' => Dec::str(Dec::hundred()->minus($total)), 'ready' => $problems === [], 'problems' => $problems];
    }

    public function transition(StrategicGoal $goal, StrategicGoalStatus $to, User $actor): StrategicGoal
    {
        $permission = match ($to) {
            StrategicGoalStatus::UnderReview => 'strategic_goals.update',
            StrategicGoalStatus::Approved => 'strategic_goals.approve',
            StrategicGoalStatus::Published => 'strategic_goals.publish',
            default => throw ValidationException::withMessages(['status' => __('performance.validation.goal_transition_invalid')]),
        };
        $this->access->authorize($this->access->inScope($actor, $permission, $goal->organization_id));
        $expected = match ($to) {
            StrategicGoalStatus::UnderReview => StrategicGoalStatus::Draft,
            StrategicGoalStatus::Approved => StrategicGoalStatus::UnderReview,
            StrategicGoalStatus::Published => StrategicGoalStatus::Approved,
            default => StrategicGoalStatus::Draft,
        };
        if ($goal->status !== $expected) {
            throw ValidationException::withMessages(['status' => __('performance.validation.goal_transition_invalid')]);
        }

        return DB::transaction(function () use ($goal, $to, $actor): StrategicGoal {
            StrategicGoal::query()->where('cycle_id', $goal->cycle_id)->where('organization_id', $goal->organization_id)->lockForUpdate()->get();
            $readiness = $this->organizationReadiness($goal->cycle_id, $goal->organization_id);
            if (! $readiness['ready']) {
                throw ValidationException::withMessages(['readiness' => $readiness['problems']]);
            }
            $old = $goal->status->value;
            $goal->forceFill([
                'status' => $to,
                'approved_by' => $to === StrategicGoalStatus::Approved ? $actor->getKey() : $goal->approved_by,
                'published_at' => $to === StrategicGoalStatus::Published ? now() : $goal->published_at,
            ])->save();
            $this->audit->record(AuditEventType::StrategicGoalStatusChanged, $actor, $goal, ['status' => $to->value], ['status' => $old]);

            return $goal;
        });
    }

    /** @param list<array<string, mixed>> $rows */
    public function replacePeriodTargets(KpiTarget $target, array $rows, User $actor): void
    {
        $this->access->authorize($this->access->inScope($actor, 'kpi_targets.manage', $target->plan->organization_id));
        if (! PerformancePlanService::isEditable($target->plan)) {
            throw ValidationException::withMessages(['target' => __('performance.errors.plan_locked')]);
        }

        $seen = [];
        foreach ($rows as $index => $row) {
            if ($row['period_type'] === 'QUARTER' && (int) $row['period_number'] > 4) {
                throw ValidationException::withMessages(["period_targets.$index.period_number" => __('performance.validation.quarter_number')]);
            }
            $key = $row['period_type'].':'.$row['period_number'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["period_targets.$index.period_number" => __('performance.validation.period_duplicate')]);
            }
            $seen[$key] = true;
        }

        DB::transaction(function () use ($target, $rows, $actor): void {
            KpiTarget::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
            $target->periodTargets()->delete();
            foreach ($rows as $row) {
                KpiPeriodTarget::query()->create(['kpi_target_id' => $target->getKey(), ...$row]);
            }
            $this->audit->record(AuditEventType::KpiPeriodTargetsChanged, $actor, $target->plan, ['target' => $target->getKey(), 'period_targets' => $rows]);
        });
    }

    private function assertDraft(StrategicGoal $goal, User $actor, string $permission): void
    {
        $this->access->authorize($this->access->inScope($actor, $permission, $goal->organization_id));
        if ($goal->status !== StrategicGoalStatus::Draft || PerformanceCycleService::isReadOnly($goal->cycle)) {
            throw ValidationException::withMessages(['goal' => __('performance.validation.goal_not_editable')]);
        }
    }

    private function assertDatesInsideCycle(PerformanceCycle $cycle, ?string $from, ?string $to): void
    {
        $start = Carbon::parse($from ?? $cycle->start_date);
        $end = Carbon::parse($to ?? $cycle->end_date);
        if ($start->lt($cycle->start_date) || $end->gt($cycle->end_date) || $end->lt($start)) {
            throw ValidationException::withMessages(['effective_from' => __('performance.errors.period_outside_cycle')]);
        }
    }
}
