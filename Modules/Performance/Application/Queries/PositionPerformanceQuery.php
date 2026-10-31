<?php

declare(strict_types=1);

namespace Modules\Performance\Application\Queries;

use App\Models\User;
use Modules\Performance\Application\Services\EpmsAccess;
use Modules\Performance\Application\Services\PerformanceCascadeService;
use Modules\Performance\Contracts\PositionPerformance;
use Modules\Performance\Domain\Models\PerformanceObjective;
use Modules\Performance\Domain\Models\PerformancePlan;

/** Position plans and plan-item usage for the position screens (moved here from their controllers). */
final class PositionPerformanceQuery implements PositionPerformance
{
    public function __construct(
        private readonly EpmsAccess $access,
        private readonly PerformanceCascadeService $cascade,
    ) {}

    public function positionPlans(User $user, string $positionId, ?string $organizationId): array
    {
        $canView = $this->access->inScope($user, 'performance_plans.view', $organizationId);
        $today = now()->toDateString();
        $planModels = $canView ? PerformancePlan::query()->where('position_id', $positionId)->where('plan_type', 'POSITION')
            ->with(['cycle:id,name_en,name_am,start_date,end_date,status', 'organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am'])
            ->orderByDesc('effective_from')->orderByDesc('version_no')->get()
            ->filter(fn ($plan) => $this->access->inScope($user, 'performance_plans.view', $plan->organization_id))->values() : collect();
        // Goals are derived per plan version from its lineage; the position itself stores none.
        $planGoals = $this->cascade->goalsForPlans($planModels);
        $plans = $planModels->map(fn ($plan) => [
            'id' => $plan->getKey(), 'title' => $plan->title, 'status' => $plan->status->value, 'version' => $plan->version_no,
            'organization' => $plan->organization?->only(['name_en', 'name_am']),
            'unit' => $plan->organizationUnit?->only(['name_en', 'name_am']),
            'cycle' => $plan->cycle?->only(['name_en', 'name_am']),
            'effective_from' => $plan->effective_from?->toDateString(), 'effective_to' => $plan->effective_to?->toDateString(),
            'is_current' => $plan->status->value === 'PUBLISHED'
                && $plan->cycle !== null && ! in_array($plan->cycle->status->value, ['CLOSED', 'CANCELLED', 'FINALIZED'], true)
                && ($plan->effective_from?->toDateString() ?? $plan->cycle?->start_date?->toDateString()) <= $today
                && ($plan->effective_to?->toDateString() ?? $plan->cycle?->end_date?->toDateString()) >= $today,
            'goals' => $planGoals[$plan->getKey()] ?? [],
        ])->values();

        return [
            'can_view' => $canView,
            'current' => $plans->where('is_current', true)->values()->all(),
            'historical' => $plans->where('is_current', false)->values()->all(),
        ];
    }

    public function currentPlanItemCounts(array $positionServiceIds): array
    {
        if ($positionServiceIds === []) {
            return [];
        }

        return PerformanceObjective::query()
            ->whereIn('position_service_id', $positionServiceIds)
            ->where('status', 'ACTIVE')
            ->whereHas('plan', fn ($plan) => $plan->where('status', 'PUBLISHED')
                ->whereHas('cycle', fn ($cycle) => $cycle->whereNotIn('status', ['FINALIZED', 'CLOSED', 'CANCELLED'])))
            ->selectRaw('position_service_id, count(*) as items')
            ->groupBy('position_service_id')
            ->pluck('items', 'position_service_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    public function positionServiceInUse(string $positionServiceId): bool
    {
        return PerformanceObjective::query()->where('position_service_id', $positionServiceId)->exists();
    }
}
