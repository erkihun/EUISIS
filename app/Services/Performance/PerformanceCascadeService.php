<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Enums\Performance\PlanStatus;
use App\Enums\Performance\PlanType;
use App\Models\DailyActivityItem;
use App\Models\EmployeePerformanceAgreement;
use App\Models\EmployeePerformanceItem;
use App\Models\OrganizationUnit;
use App\Models\PerformanceObjective;
use App\Models\PerformancePlan;
use App\Models\PositionService;
use App\Models\StrategicGoal;
use App\Models\StrategicGoalAllocation;
use App\Models\User;
use App\Services\Performance\Calculation\Dec;
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
        $plan = $objective->plan;
        $key = $plan->cycle_id.':'.$plan->organization_id;

        return $this->graphs[$key] ??= PerformanceObjective::query()
            ->whereHas('plan', fn ($q) => $q->where('cycle_id', $plan->cycle_id)->where('organization_id', $plan->organization_id))
            ->with(['plan.organizationUnit', 'plan.position', 'strategicGoal', 'allocation.unit', 'positionService'])
            ->get()->keyBy('id');
    }

    public function goalFor(PerformanceObjective $objective): ?StrategicGoal
    {
        $graph = $this->graph($objective);
        $seen = [];
        $current = $graph->get($objective->getKey(), $objective);
        while ($current !== null && ! in_array($current->getKey(), $seen, true)) {
            if ($current->strategic_goal_id !== null) {
                return $current->strategicGoal;
            }
            $seen[] = $current->getKey();
            $current = $graph->get($current->parent_objective_id);
        }

        return null;
    }

    /** Ordered, historical lineage; one eager-loaded graph per organization/cycle. */
    public function trace(PerformanceObjective $objective): array
    {
        $graph = $this->graph($objective);
        $chain = [];
        $current = $graph->get($objective->getKey(), $objective);
        $seen = [];
        while ($current !== null && ! in_array($current->getKey(), $seen, true)) {
            $seen[] = $current->getKey();
            $chain[] = $current;
            $current = $graph->get($current->parent_objective_id);
        }
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
