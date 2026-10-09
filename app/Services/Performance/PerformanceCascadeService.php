<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Enums\Performance\PlanStatus;
use App\Enums\Performance\PlanType;
use App\Models\DailyActivityItem;
use App\Models\Employee;
use App\Models\EmployeePerformanceAgreement;
use App\Models\EmployeePerformanceItem;
use App\Models\OrganizationUnit;
use App\Models\PerformanceObjective;
use App\Models\PerformancePlan;
use App\Models\PerformancePlanScore;
use App\Models\Position;
use App\Models\PositionService;
use App\Models\StrategicGoal;
use App\Models\StrategicGoalAllocation;
use App\Models\User;
use App\Services\Performance\Calculation\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Connects the existing planning entities; master data never owns a goal. */
final class PerformanceCascadeService
{
    private array $graphs = [];

    private array $cascadeGraphs = [];

    /** Responsibility is allocated to this unit or one of its ancestors. */
    public function hasResponsibility(PerformancePlan $plan, StrategicGoal $goal): bool
    {
        $units = [];
        $unit = $plan->organization_unit_id;
        while ($unit !== null && ! in_array($unit, $units, true)) {
            $units[] = $unit;
            $unit = OrganizationUnit::query()->whereKey($unit)->value('parent_unit_id');
        }

        return $goal->allocations()->whereIn('organization_unit_id', $units)->exists();
    }

    /** Validates IDs in the service layer, including callers outside HTTP. */
    public function links(PerformancePlan $plan, array $data, ?PerformanceObjective $parent = null, ?PerformanceObjective $existing = null): array
    {
        $this->graphs = [];
        $this->cascadeGraphs = [];
        if ($parent !== null && ($parent->performance_plan_id !== $plan->parent_plan_id
            || $parent->plan->status !== PlanStatus::Published || $parent->status !== 'ACTIVE'
            || $parent->plan->organization_id !== $plan->organization_id || $parent->plan->cycle_id !== $plan->cycle_id)) {
            throw ValidationException::withMessages(['parent_objective_id' => __('performance.errors.parent_structure')]);
        }
        $goalId = $parent !== null ? $this->goalFor($parent)?->getKey() : ($data['strategic_goal_id'] ?? null);
        if ($parent !== null && ! empty($data['strategic_goal_id']) && $data['strategic_goal_id'] !== $goalId) {
            throw ValidationException::withMessages(['strategic_goal_id' => __('performance.validation.goal_outside_plan')]);
        }
        if ($goalId !== null) {
            $goal = StrategicGoal::query()->findOrFail($goalId);
            if ($goal->cycle_id !== $plan->cycle_id || $goal->organization_id !== $plan->organization_id
                || ($plan->plan_type !== PlanType::Organization && $parent === null)) {
                throw ValidationException::withMessages(['strategic_goal_id' => __('performance.validation.goal_outside_plan')]);
            }
        }

        $allocationId = null;
        if ($goalId !== null && $plan->plan_type !== PlanType::Organization) {
            $units = [];
            $unit = $plan->organization_unit_id;
            while ($unit !== null && ! in_array($unit, $units, true)) {
                $units[] = $unit;
                $unit = OrganizationUnit::query()->whereKey($unit)->value('parent_unit_id');
            }
            $allocations = StrategicGoalAllocation::query()->where('strategic_goal_id', $goalId)->whereIn('organization_unit_id', $units)->get();
            // Prefer the closest allocated unit. Descendant unit plans retain responsibility.
            foreach ($units as $unitId) {
                $allocationId = $allocations->firstWhere('organization_unit_id', $unitId)?->getKey();
                if ($allocationId !== null) {
                    break;
                }
            }
            // Existing items retain their recorded responsibility after structure changes.
            if ($existing !== null && $existing->strategic_goal_id === $goalId && $existing->strategic_goal_allocation_id !== null) {
                $allocationId = $existing->strategic_goal_allocation_id;
            }
            if ($allocationId === null) {
                throw ValidationException::withMessages(['strategic_goal_allocation_id' => __('performance.validation.unit_allocation_required')]);
            }
        }
        if (! empty($data['strategic_goal_allocation_id']) && $data['strategic_goal_allocation_id'] !== $allocationId) {
            throw ValidationException::withMessages(['strategic_goal_allocation_id' => __('performance.validation.unit_allocation_required')]);
        }

        $serviceId = $data['position_service_id'] ?? null;
        if ($serviceId !== null && $serviceId !== '') {
            $retained = $existing !== null && in_array($existing->performance_plan_id, [$plan->getKey(), $plan->supersedes_plan_id], true) && $existing->position_service_id === $serviceId;
            $valid = $retained || ($plan->plan_type === PlanType::Position && PositionService::query()->whereKey($serviceId)
                ->where('position_id', $plan->position_id)->where('organization_id', $plan->organization_id)->where('is_active', true)->exists());
            if (! $valid) {
                throw ValidationException::withMessages(['position_service_id' => __('performance.validation.service_outside_position')]);
            }
        }
        if (isset($data['local_weight_percent'], $data['weight']) && ! Dec::of($data['local_weight_percent'])->isEqualTo(Dec::of($data['weight']))) {
            throw ValidationException::withMessages(['local_weight_percent' => __('performance.validation.local_weight_mismatch')]);
        }
        if ($plan->plan_type === PlanType::Organization && isset($data['absolute_weight_percent'], $data['weight'])
            && ! Dec::of($data['absolute_weight_percent'])->isEqualTo(Dec::of($data['weight']))) {
            throw ValidationException::withMessages(['absolute_weight_percent' => __('performance.validation.absolute_weight_mismatch')]);
        }

        return [
            'strategic_goal_id' => $goalId,
            'strategic_goal_allocation_id' => $allocationId,
            'position_service_id' => $serviceId ?: null,
            'local_weight_percent' => $data['weight'] ?? $data['local_weight_percent'] ?? null,
        ];
    }

    /** Absolute organization percentage points, not an employee appraisal score. */
    public function contribution(string $allocation, string $achievement): string
    {
        $weight = Dec::of($allocation);
        $result = Dec::of($achievement);
        if ($weight->isNegative() || $weight->isGreaterThan(100) || $result->isNegative()) {
            throw ValidationException::withMessages(['weight' => __('performance.validation.allocation_total')]);
        }

        return Dec::str(Dec::div($weight->multipliedBy($result), Dec::hundred()));
    }

    private function graph(PerformanceObjective $objective): Collection
    {
        return $this->planGraph($objective->plan->cycle_id, $objective->plan->organization_id);
    }

    private function planGraph(string $cycleId, string $organizationId): Collection
    {
        return $this->graphs[$cycleId.':'.$organizationId] ??= PerformanceObjective::query()
            ->whereHas('plan', fn ($q) => $q->where('cycle_id', $cycleId)->where('organization_id', $organizationId))
            ->with(['plan.organizationUnit', 'plan.position', 'strategicGoal', 'allocation.unit', 'positionService'])
            ->get()->keyBy('id');
    }

    /** @return list<PerformanceObjective> the objective first, then its upstream parents (cycle-safe) */
    private function chain(Collection $graph, PerformanceObjective $objective): array
    {
        $chain = [];
        $current = $graph->get($objective->getKey(), $objective);
        while ($current !== null && ! isset($chain[$current->getKey()])) {
            $chain[$current->getKey()] = $current;
            $current = $graph->get($current->parent_objective_id);
        }

        return array_values($chain);
    }

    public function goalFor(PerformanceObjective $objective): ?StrategicGoal
    {
        return collect($this->chain($this->graph($objective), $objective))->first(fn ($row) => $row->strategic_goal_id !== null)?->strategicGoal;
    }

    /** Ordered, historical lineage; one eager-loaded graph per organization/cycle. */
    public function trace(PerformanceObjective $objective): array
    {
        $chain = $this->chain($this->graph($objective), $objective);
        $goal = $this->goalFor($objective);
        $allocation = collect($chain)->first(fn ($row) => $row->strategic_goal_allocation_id !== null)?->allocation;
        $steps = $goal ? [['type' => 'GOAL', 'id' => $goal->getKey(), 'label' => $goal->code.' · '.$goal->name_en, 'weight' => $goal->weight_percent]] : [];
        if ($allocation !== null) {
            $steps[] = ['type' => 'ALLOCATION', 'id' => $allocation->getKey(), 'label' => $allocation->unit?->name_en, 'weight' => $allocation->organization_contribution_percent];
        }
        foreach (array_reverse($chain) as $row) {
            $steps[] = ['type' => $row->plan->plan_type->value, 'id' => $row->getKey(), 'label' => $row->code.' · '.$row->title_en,
                'plan_id' => $row->performance_plan_id, 'unit' => $row->plan->organizationUnit?->name_en, 'position' => $row->plan->position?->title_en];
        }
        if ($objective->position_service_id !== null) {
            $steps[] = ['type' => 'SERVICE', 'id' => $objective->position_service_id, 'label' => $objective->positionService?->name_en];
        }

        return $steps;
    }

    public function activityTrace(DailyActivityItem $activity): array
    {
        $item = $activity->performanceItem;

        return $item?->objective ? $this->trace($item->objective) : [];
    }

    /**
     * Goals each plan contributes to, derived from its active items' lineage.
     *
     * @param  iterable<PerformancePlan>  $plans
     * @return array<string, list<array<string, mixed>>> plan id => goals
     */
    public function goalsForPlans(iterable $plans): array
    {
        $result = [];
        foreach ($plans as $plan) {
            $graph = $this->planGraph($plan->cycle_id, $plan->organization_id);
            $result[$plan->getKey()] = $this->goalRows($graph, $graph->where('performance_plan_id', $plan->getKey())->where('status', 'ACTIVE'));
        }

        return $result;
    }

    /** @return list<array<string, mixed>> one row per position plan version, each with its own goals */
    public function goalsForPosition(Position $position, ?string $cycleId = null): array
    {
        $plans = PerformancePlan::query()->where('plan_type', PlanType::Position->value)->where('position_id', $position->getKey())
            ->when($cycleId !== null, fn ($q) => $q->where('cycle_id', $cycleId))
            ->orderBy('effective_from')->orderBy('version_no')->get();
        $goals = $this->goalsForPlans($plans);

        return $plans->map(fn (PerformancePlan $plan) => [
            'performance_plan_id' => $plan->getKey(), 'cycle_id' => $plan->cycle_id, 'organization_unit_id' => $plan->organization_unit_id,
            'version_no' => $plan->version_no, 'status' => $plan->status->value, 'goals' => $goals[$plan->getKey()],
        ])->values()->all();
    }

    /**
     * One row per agreement, current and historical. Each keeps the assignment,
     * position, unit and plan it was made under, so a transfer never moves
     * earlier contributions to the new post.
     *
     * @return list<array<string, mixed>>
     */
    public function goalsForEmployee(Employee $employee, ?string $cycleId = null): array
    {
        return EmployeePerformanceAgreement::query()->where('employee_id', $employee->getKey())
            ->when($cycleId !== null, fn ($q) => $q->where('cycle_id', $cycleId))
            ->with('allItems.objective.plan')->orderBy('effective_from')->orderBy('agreement_version')->get()
            ->map(function (EmployeePerformanceAgreement $agreement): array {
                $objectives = $agreement->allItems->pluck('objective')->filter();
                $plan = $objectives->first()?->plan;

                return [
                    'agreement_id' => $agreement->getKey(), 'employee_assignment_id' => $agreement->employee_assignment_id,
                    'cycle_id' => $agreement->cycle_id, 'performance_plan_id' => $agreement->performance_plan_id,
                    'organization_unit_id' => $agreement->organization_unit_id, 'position_id' => $agreement->position_id,
                    'status' => $agreement->status?->value, 'effective_from' => $agreement->effective_from?->toDateString(),
                    'effective_to' => $agreement->effective_to?->toDateString(),
                    'goals' => $plan !== null ? $this->goalRows($this->planGraph($plan->cycle_id, $plan->organization_id), $objectives) : [],
                ];
            })->values()->all();
    }

    /**
     * Each allocation's contribution in absolute organization percentage
     * points: allocation weight × achievement of the allocated responsibility.
     *
     * The achievement comes from the latest stored score of the allocated
     * unit's published plan, weighted by the local weights of the items linked
     * to that allocation (or an earlier version of it). Unmeasured items are
     * excluded and the allocation is flagged incomplete; nothing unmeasured
     * counts as zero. This is a derived view; the official organization score
     * remains the organization plan's KPI roll-up, never a sum of employees.
     *
     * @param  iterable<StrategicGoal>  $goals
     * @return array{allocations: array<string, array<string, mixed>>, goals: array<string, array{contribution: ?string, complete: bool}>}
     */
    public function allocationContributions(iterable $goals): array
    {
        $goals = new EloquentCollection(collect($goals)->values()->all());
        $result = ['allocations' => [], 'goals' => []];
        if ($goals->isEmpty()) {
            return $result;
        }
        $goals->loadMissing('allocations');
        $plans = PerformancePlan::query()->where('plan_type', PlanType::Unit->value)->where('status', PlanStatus::Published->value)
            ->whereIn('cycle_id', $goals->pluck('cycle_id')->unique())->whereIn('organization_id', $goals->pluck('organization_id')->unique())
            ->with(['objectives' => fn ($q) => $q->where('status', 'ACTIVE')->whereNotNull('strategic_goal_allocation_id')->with('allocation.goal:id,code')])
            ->get()->keyBy(fn ($plan) => $plan->cycle_id.':'.$plan->organization_id.':'.$plan->organization_unit_id);
        $scores = PerformancePlanScore::query()->whereIn('performance_plan_id', $plans->pluck('id'))
            ->whereRaw('as_of = (select max(latest.as_of) from performance_plan_scores latest where latest.performance_plan_id = performance_plan_scores.performance_plan_id)')
            ->get()->keyBy('performance_plan_id');

        foreach ($goals as $goal) {
            $total = Dec::zero();
            $complete = $goal->allocations->isNotEmpty();
            foreach ($goal->allocations as $allocation) {
                $plan = $plans->get($goal->cycle_id.':'.$goal->organization_id.':'.$allocation->organization_unit_id);
                $score = $plan !== null ? $scores->get($plan->getKey()) : null;
                // Version successors copy allocations, so match the unit within the goal's code family.
                $linked = $plan?->objectives->filter(fn ($objective) => $objective->allocation?->organization_unit_id === $allocation->organization_unit_id
                    && $objective->allocation?->goal?->code === $goal->code)->keyBy('id') ?? collect();
                [$achievement, $measured] = $this->linkedAchievement($score?->trace_json['objectives'] ?? [], $linked);
                $contribution = $achievement !== null && ! $achievement->isNegative()
                    ? $this->contribution((string) $allocation->organization_contribution_percent, Dec::str($achievement)) : null;
                $isComplete = $contribution !== null && $measured === $linked->count();
                $complete = $complete && $isComplete;
                $total = $contribution !== null ? $total->plus(Dec::of($contribution)) : $total;
                $result['allocations'][$allocation->getKey()] = [
                    'plan_id' => $plan?->getKey(), 'as_of' => $score?->as_of?->toDateString(),
                    'achievement' => Dec::str($achievement), 'contribution' => $contribution, 'complete' => $isComplete,
                ];
            }
            $result['goals'][$goal->getKey()] = ['contribution' => Dec::str($total), 'complete' => $complete];
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $traceObjectives  stored plan-score trace rows
     * @return array{0: ?BigDecimal, 1: int} weighted achievement of the measured linked items, and their count
     */
    private function linkedAchievement(array $traceObjectives, Collection $linked): array
    {
        $weighted = Dec::zero();
        $weights = Dec::zero();
        $measured = 0;
        foreach ($traceObjectives as $row) {
            $reported = collect($row['targets'] ?? [])->contains(fn ($target) => ($target['achievement'] ?? null) !== null);
            if (! $linked->has($row['objective_id'] ?? null) || ! $reported || ($row['score'] ?? null) === null) {
                continue;
            }
            $measured++;
            $weight = Dec::of($row['weight'] ?? $linked->get($row['objective_id'])->weight);
            $weighted = $weighted->plus(Dec::of($row['score'])->multipliedBy($weight));
            $weights = $weights->plus($weight);
        }

        return [$measured > 0 && $weights->isPositive() ? Dec::div($weighted, $weights) : null, $measured];
    }

    /** @return list<array<string, mixed>> distinct goals reached from the given objectives, with the responsible allocation */
    private function goalRows(Collection $graph, iterable $objectives): array
    {
        $rows = [];
        foreach ($objectives as $objective) {
            $chain = collect($this->chain($graph, $objective));
            $goal = $chain->first(fn ($row) => $row->strategic_goal_id !== null)?->strategicGoal;
            if ($goal === null || isset($rows[$goal->getKey()])) {
                continue;
            }
            $allocation = $chain->first(fn ($row) => $row->strategic_goal_allocation_id !== null)?->allocation;
            $rows[$goal->getKey()] = [
                'id' => $goal->getKey(), 'code' => $goal->code, 'name_en' => $goal->name_en, 'name_am' => $goal->name_am,
                'version_no' => $goal->version_no, 'weight_percent' => $goal->weight_percent,
                'allocation_id' => $allocation?->getKey(), 'unit' => $allocation?->unit?->name_en, 'unit_am' => $allocation?->unit?->name_am,
                'allocation_percent' => $allocation?->organization_contribution_percent,
            ];
        }

        return array_values($rows);
    }

    /** Read-only goal cascade, including only employee agreements the actor may view. */
    public function goalCascade(StrategicGoal $goal, User $actor): array
    {
        $access = app(EpmsAccess::class);
        $access->authorize($access->inScope($actor, 'strategic_goals.view', $goal->organization_id));
        $key = $goal->cycle_id.':'.$goal->organization_id.':'.$actor->getKey();
        if (! isset($this->cascadeGraphs[$key])) {
            $objectives = PerformanceObjective::query()->whereHas('plan', fn ($q) => $q->where('cycle_id', $goal->cycle_id)->where('organization_id', $goal->organization_id))
                ->with(['plan.organizationUnit', 'plan.position', 'positionService', 'targets.kpi'])->get();
            $agreements = $access->constrainAgreements(EmployeePerformanceAgreement::query()->where('cycle_id', $goal->cycle_id)->where('organization_id', $goal->organization_id), $actor)->pluck('id');
            $employees = EmployeePerformanceItem::query()->whereIn('objective_id', $objectives->pluck('id'))->whereIn('agreement_id', $agreements)
                ->with('agreement.employee')->get()->groupBy('objective_id');
            $this->cascadeGraphs[$key] = [$objectives, $employees];
        }
        [$objectives, $employees] = $this->cascadeGraphs[$key];
        $ids = $objectives->where('strategic_goal_id', $goal->getKey())->pluck('id')->all();
        do {
            $previous = count($ids);
            $ids = array_values(array_unique([...$ids, ...$objectives->whereIn('parent_objective_id', $ids)->pluck('id')->all()]));
        } while (count($ids) !== $previous);

        return $objectives->whereIn('id', $ids)->map(fn ($row) => [
            'id' => $row->getKey(), 'plan_id' => $row->performance_plan_id, 'level' => $row->plan->plan_type->value,
            'title' => $row->title_en, 'unit' => $row->plan->organizationUnit?->name_en,
            'position' => $row->plan->position?->title_en, 'service' => $row->positionService?->name_en,
            'status' => $row->plan->status->value, 'version' => $row->plan->version_no,
            'targets' => $row->targets->map(fn ($target) => ['kpi' => $target->kpi?->code, 'target' => $target->target_value, 'weight' => $target->weight])->all(),
            'employees' => ($employees->get($row->getKey()) ?? collect())->map(fn ($item) => $item->agreement?->employee?->full_name)->filter()->unique()->values()->all(),
        ])->values()->all();
    }
}
