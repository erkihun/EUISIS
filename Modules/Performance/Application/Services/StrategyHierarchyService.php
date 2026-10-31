<?php

declare(strict_types=1);

namespace Modules\Performance\Application\Services;

use App\Enums\AuditEventType;
use App\Models\User;
use App\Support\Math\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Performance\Contracts\Data\PlanStatus;
use Modules\Performance\Domain\Enums\ObjectiveType;
use Modules\Performance\Domain\Enums\StrategicGoalStatus;
use Modules\Performance\Domain\Models\MainStrategy;
use Modules\Performance\Domain\Models\PerformanceCycle;
use Modules\Performance\Domain\Models\PerformanceObjective;
use Modules\Performance\Domain\Models\PerformancePlan;
use Modules\Performance\Domain\Models\StrategicGoal;
use Modules\Performance\Domain\Models\Strategy;

/**
 * The WLMS-style entry of the strategy hierarchy (ADR 0019):
 *
 *   Σ main strategies of an organization's cycle ≤ 100
 *   Σ strategies of a main strategy = the main strategy's weight
 *   strategic goal weight = its strategy's weight
 *   Σ objectives of a goal = the goal's weight (they live in the organization plan)
 *   Σ activities of an objective = 100, at least two activities
 */
final class StrategyHierarchyService
{
    public function __construct(
        private readonly EpmsAccess $access,
        private readonly EpmsAudit $audit,
        private readonly StrategicPlanningService $planning,
        private readonly PerformancePlanService $plans,
    ) {}

    // ── Main strategies ──────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    public function saveMainStrategy(?MainStrategy $main, array $data, User $actor): MainStrategy
    {
        $cycleId = $main?->cycle_id ?? $data['cycle_id'];
        $organizationId = $main?->organization_id ?? $data['organization_id'];
        $this->access->authorize($this->access->inScope($actor, $main === null ? 'strategic_goals.create' : 'strategic_goals.update', $organizationId));
        $cycle = PerformanceCycle::query()->findOrFail($cycleId);
        $this->assertCycleOpen($cycle, $organizationId);

        $weight = Dec::of($data['weight_percent']);
        $strategies = collect($data['strategies'])->values();
        $total = Dec::sum($strategies->map(fn (array $row) => Dec::of($row['weight_percent'])));
        if (! $total->isEqualTo($weight)) {
            throw ValidationException::withMessages(['strategies' => __('performance.hierarchy.strategies_total', ['total' => Dec::str($total, 2), 'weight' => Dec::str($weight, 2)])]);
        }

        return DB::transaction(function () use ($main, $data, $actor, $cycleId, $organizationId, $weight, $strategies): MainStrategy {
            $siblings = MainStrategy::query()->where('cycle_id', $cycleId)->where('organization_id', $organizationId)->lockForUpdate()->get();
            $others = $siblings->when($main !== null, fn ($rows) => $rows->where('id', '!=', $main->getKey()));
            $overall = Dec::sum($others->map(fn (MainStrategy $row) => Dec::of($row->weight_percent)))->plus($weight);
            if ($overall->isGreaterThan(100)) {
                $remaining = Dec::hundred()->minus(Dec::sum($others->map(fn (MainStrategy $row) => Dec::of($row->weight_percent))));
                throw ValidationException::withMessages(['weight_percent' => __('performance.hierarchy.main_total', ['remaining' => Dec::str($remaining, 2)])]);
            }

            $old = $main?->only(['title', 'description', 'weight_percent']);
            $main ??= new MainStrategy([
                'cycle_id' => $cycleId,
                'organization_id' => $organizationId,
                'sort_order' => (int) $siblings->max('sort_order') + 1,
                'created_by' => $actor->getKey(),
            ]);
            $main->fill(['title' => $data['title'], 'description' => $data['description'] ?? null, 'weight_percent' => Dec::str($weight)])->save();
            $this->syncStrategies($main, $strategies);
            $this->audit->record(AuditEventType::StrategicGoalChanged, $actor, $main, [
                'main_strategy' => $main->only(['title', 'weight_percent']),
                'strategies' => $main->strategies()->get(['title', 'weight_percent'])->toArray(),
            ], $old);

            return $main;
        });
    }

    public function deleteMainStrategy(MainStrategy $main, User $actor): void
    {
        $this->access->authorize($this->access->inScope($actor, 'strategic_goals.delete_draft', $main->organization_id));
        if (StrategicGoal::query()->whereIn('strategy_id', $main->strategies()->select('id'))->exists()) {
            throw ValidationException::withMessages(['main_strategy' => __('performance.hierarchy.has_goals')]);
        }
        $this->audit->record(AuditEventType::StrategicGoalChanged, $actor, $main, ['deleted_main_strategy' => $main->title]);
        $main->delete();
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function syncStrategies(MainStrategy $main, Collection $rows): void
    {
        $existing = $main->strategies()->get()->keyBy('id');
        $kept = [];
        foreach ($rows as $index => $row) {
            $id = $row['id'] ?? null;
            if ($id !== null && ! $existing->has($id)) {
                throw ValidationException::withMessages(["strategies.$index.id" => __('performance.hierarchy.unknown_row')]);
            }
            $strategy = $id !== null ? $existing->get($id) : new Strategy(['main_strategy_id' => $main->getKey()]);
            $weight = Dec::str(Dec::of($row['weight_percent']));
            if ($strategy->exists) {
                $this->followStrategy($strategy, $row['title'], $weight, $index);
            }
            $strategy->fill(['title' => $row['title'], 'description' => $row['description'] ?? null, 'weight_percent' => $weight, 'sort_order' => $index + 1])->save();
            $kept[] = $strategy->getKey();
        }

        $removed = $existing->keys()->diff($kept);
        if ($removed->isNotEmpty() && StrategicGoal::query()->whereIn('strategy_id', $removed)->exists()) {
            throw ValidationException::withMessages(['strategies' => __('performance.hierarchy.strategy_has_goal')]);
        }
        Strategy::query()->whereIn('id', $removed)->delete();
    }

    /**
     * A draft goal follows its strategy's title and weight; a goal under
     * review or published keeps them, so they cannot change underneath it.
     */
    private function followStrategy(Strategy $strategy, string $title, string $weight, int $index): void
    {
        $goal = $this->liveGoal($strategy);
        if ($goal === null) {
            return;
        }
        $weightChanged = ! Dec::of($strategy->weight_percent)->isEqualTo(Dec::of($weight));
        if ($goal->status !== StrategicGoalStatus::Draft) {
            if ($weightChanged || $strategy->title !== $title) {
                throw ValidationException::withMessages(["strategies.$index.weight_percent" => __('performance.hierarchy.goal_locked')]);
            }

            return;
        }
        if ($weightChanged) {
            $allocated = Dec::sum($goal->allocations()->get()->map(fn ($row) => Dec::of($row->organization_contribution_percent)));
            if ($allocated->isGreaterThan(Dec::of($weight))) {
                throw ValidationException::withMessages(["strategies.$index.weight_percent" => __('performance.validation.allocation_total', ['total' => Dec::str($allocated, 2), 'weight' => Dec::str(Dec::of($weight), 2)])]);
            }
        }
        $goal->forceFill(['weight_percent' => $weight, 'name_am' => $title, 'name_en' => $title])->save();
    }

    // ── Strategic goal with its objectives and activities ────────────────

    /** @param array<string, mixed> $data */
    public function saveGoal(?StrategicGoal $goal, array $data, User $actor): StrategicGoal
    {
        $strategy = Strategy::query()->with('mainStrategy')->findOrFail($goal?->strategy_id ?? $data['strategy_id']);
        $main = $strategy->mainStrategy;
        $weight = Dec::of($strategy->weight_percent);
        $objectives = collect($data['objectives'])->values();

        $total = Dec::sum($objectives->map(fn (array $row) => Dec::of($row['weight_percent'])));
        if (! $total->isEqualTo($weight)) {
            throw ValidationException::withMessages(['objectives' => __('performance.hierarchy.objectives_total', ['total' => Dec::str($total, 2), 'weight' => Dec::str($weight, 2)])]);
        }
        foreach ($objectives as $index => $objective) {
            $activities = collect($objective['activities']);
            $sum = Dec::sum($activities->map(fn (array $row) => Dec::of($row['weight_percent'])));
            if (! $sum->isEqualTo(100)) {
                throw ValidationException::withMessages(["objectives.$index.activities" => __('performance.hierarchy.activities_total', ['total' => Dec::str($sum, 2)])]);
            }
        }

        return DB::transaction(function () use ($goal, $data, $actor, $strategy, $main, $weight, $objectives): StrategicGoal {
            if ($goal === null) {
                $taken = StrategicGoal::query()->where('strategy_id', $strategy->getKey())->lockForUpdate()->exists();
                if ($taken) {
                    throw ValidationException::withMessages(['strategy_id' => __('performance.hierarchy.strategy_taken')]);
                }
                $goal = $this->planning->createGoal([
                    'cycle_id' => $main->cycle_id,
                    'organization_id' => $main->organization_id,
                    'strategy_id' => $strategy->getKey(),
                    'code' => $this->goalCode($main, $strategy),
                    'name_am' => $strategy->title,
                    'name_en' => $strategy->title,
                    'description_am' => $data['description'] ?? null,
                    'weight_percent' => Dec::str($weight),
                    'is_active' => (bool) ($data['is_active'] ?? true),
                    'sort_order' => $main->sort_order * 100 + $strategy->sort_order,
                ], $actor);
            } else {
                $goal = $this->planning->updateGoal($goal, [
                    'name_am' => $strategy->title,
                    'name_en' => $strategy->title,
                    'description_am' => $data['description'] ?? null,
                    'weight_percent' => Dec::str($weight),
                    'is_active' => (bool) ($data['is_active'] ?? $goal->is_active),
                ], $actor);
            }

            $plan = $this->organizationPlan($goal, $actor);
            $this->syncObjectives($goal, $plan, $objectives, $actor);

            return $goal;
        });
    }

    /**
     * Delete a draft goal with its objectives and activities, as the WLMS list
     * allowed. The plan's own checks still apply (it must be an editable draft).
     */
    public function deleteGoal(StrategicGoal $goal, User $actor): void
    {
        DB::transaction(function () use ($goal, $actor): void {
            $plan = $this->livePlan($goal->cycle_id, $goal->organization_id);
            if ($plan !== null) {
                foreach ($plan->objectives()->where('strategic_goal_id', $goal->getKey())->get() as $objective) {
                    $this->plans->removeObjective($objective, $actor);
                }
            }
            $this->planning->deleteGoal($goal, $actor);
        });
    }

    /** The goal of a strategy that is still in force (the newest non-superseded version). */
    public function liveGoal(Strategy $strategy): ?StrategicGoal
    {
        return $strategy->goals()->where('status', '!=', StrategicGoalStatus::Superseded->value)->orderByDesc('version_no')->first();
    }

    public function livePlan(string $cycleId, string $organizationId): ?PerformancePlan
    {
        return PerformancePlan::query()->where('cycle_id', $cycleId)->where('organization_id', $organizationId)
            ->where('plan_type', 'ORGANIZATION')->whereNotIn('status', [PlanStatus::Superseded->value, PlanStatus::Closed->value])
            ->orderByDesc('version_no')->first();
    }

    /**
     * Whether the actor may write the objectives into the organization plan.
     * Organization plans need plan oversight (EpmsAccess::canPlan), which a
     * user who may only register goals does not have.
     */
    public function canWritePlan(User $actor, string $cycleId, string $organizationId): bool
    {
        if ($this->livePlan($cycleId, $organizationId) === null) {
            return $this->access->canPlan($actor, 'performance_plans.create', $organizationId, null);
        }

        return $this->access->canPlan($actor, 'performance_objectives.manage', $organizationId, null)
            || $this->access->canPlan($actor, 'performance_plans.update', $organizationId, null);
    }

    /** The organization's live plan for the goal's cycle, created as a draft when there is none yet. */
    public function organizationPlan(StrategicGoal $goal, User $actor, bool $create = true): ?PerformancePlan
    {
        $plan = $this->livePlan($goal->cycle_id, $goal->organization_id);
        if ($plan !== null || ! $create) {
            return $plan;
        }

        $goal->loadMissing(['organization:id,name_en,name_am', 'cycle:id,name_en,name_am']);

        return $this->plans->create([
            'plan_type' => 'ORGANIZATION',
            'cycle_id' => $goal->cycle_id,
            'organization_id' => $goal->organization_id,
            'title' => trim(($goal->organization?->name_am ?: $goal->organization?->name_en).' — '.($goal->cycle?->name_am ?: $goal->cycle?->name_en)),
        ], $actor);
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function syncObjectives(StrategicGoal $goal, PerformancePlan $plan, Collection $rows, User $actor): void
    {
        $existing = $plan->objectives()->where('strategic_goal_id', $goal->getKey())->get()->keyBy('id');
        $codes = $plan->objectives()->pluck('code')->all();
        $kept = [];
        foreach ($rows as $index => $row) {
            $id = $row['id'] ?? null;
            if ($id !== null && ! $existing->has($id)) {
                throw ValidationException::withMessages(["objectives.$index.id" => __('performance.hierarchy.unknown_row')]);
            }
            $weight = Dec::str(Dec::of($row['weight_percent']));
            $fields = ['title_am' => $row['title'], 'title_en' => $row['title'], 'weight' => $weight, 'absolute_weight_percent' => $weight, 'sort_order' => $index + 1];
            if ($id !== null) {
                $objective = $this->plans->updateObjective($existing->get($id), $fields, $actor);
            } else {
                $code = $this->nextCode($goal->code, $codes);
                $codes[] = $code;
                $objective = $this->plans->addObjective($plan, [
                    ...$fields,
                    'code' => $code,
                    'objective_type' => ObjectiveType::Strategic->value,
                    'strategic_goal_id' => $goal->getKey(),
                ], $actor);
            }
            $this->syncActivities($objective, collect($row['activities'])->values(), $index);
            $kept[] = $objective->getKey();
        }

        foreach ($existing->except($kept) as $removed) {
            $this->plans->removeObjective($removed, $actor);
        }
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function syncActivities(PerformanceObjective $objective, Collection $rows, int $objectiveIndex): void
    {
        $existing = $objective->activities()->get()->keyBy('id');
        $kept = [];
        foreach ($rows as $index => $row) {
            $id = $row['id'] ?? null;
            if ($id !== null && ! $existing->has($id)) {
                throw ValidationException::withMessages(["objectives.$objectiveIndex.activities.$index.id" => __('performance.hierarchy.unknown_row')]);
            }
            $activity = $id !== null ? $existing->get($id) : $objective->activities()->make();
            $activity->fill([
                'title' => $row['title'],
                'weight_percent' => Dec::str(Dec::of($row['weight_percent'])),
                'time_minutes' => $row['time_minutes'] ?? null,
                'sort_order' => $index + 1,
            ])->save();
            $kept[] = $activity->getKey();
        }
        $objective->activities()->whereNotIn('id', $kept)->delete();
    }

    /** SG-<main number>-<strategy number>, the numbering the overview shows. */
    private function goalCode(MainStrategy $main, Strategy $strategy): string
    {
        $mainNumber = MainStrategy::query()->where('cycle_id', $main->cycle_id)->where('organization_id', $main->organization_id)
            ->where(fn ($query) => $query->where('sort_order', '<', $main->sort_order)
                ->orWhere(fn ($same) => $same->where('sort_order', $main->sort_order)->where('created_at', '<', $main->created_at)))
            ->count() + 1;
        $base = 'SG-'.$mainNumber.'-'.$strategy->sort_order;
        $taken = StrategicGoal::query()->where('cycle_id', $main->cycle_id)->where('organization_id', $main->organization_id)
            ->where('code', 'like', $base.'%')->pluck('code')->all();
        $code = $base;
        for ($suffix = 2; in_array($code, $taken, true); $suffix++) {
            $code = $base.'-'.$suffix;
        }

        return $code;
    }

    /** @param list<string> $taken */
    private function nextCode(string $prefix, array $taken): string
    {
        $number = 1;
        while (in_array($prefix.'-O'.$number, $taken, true)) {
            $number++;
        }

        return $prefix.'-O'.$number;
    }

    private function assertCycleOpen(PerformanceCycle $cycle, string $organizationId): void
    {
        if (PerformanceCycleService::isReadOnly($cycle)) {
            throw ValidationException::withMessages(['cycle_id' => __('performance.errors.cycle_read_only')]);
        }
        if ($cycle->organization_id !== null && $cycle->organization_id !== $organizationId) {
            throw ValidationException::withMessages(['cycle_id' => __('performance.errors.cycle_other_organization')]);
        }
    }

    /** Share of a part in its whole, for the overview (null when the whole is zero). */
    public static function share(string $part, string $whole): ?string
    {
        $whole = Dec::of($whole);

        return $whole === null || $whole->isZero() ? null : Dec::str(Dec::pct(Dec::of($part) ?? BigDecimal::zero(), $whole), 2);
    }
}
