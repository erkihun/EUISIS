<?php

declare(strict_types=1);

namespace App\Http\Controllers\Performance;

use App\Enums\Performance\GoalAllocationType;
use App\Enums\Performance\StrategicGoalStatus;
use App\Http\Requests\Performance\SaveKpiPeriodTargetsRequest;
use App\Http\Requests\Performance\SaveStrategicGoalAllocationRequest;
use App\Http\Requests\Performance\SaveStrategicGoalRequest;
use App\Models\KpiTarget;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\PerformanceCycle;
use App\Models\StrategicGoal;
use App\Models\StrategicGoalAllocation;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\Performance\StrategicPlanningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StrategicGoalController extends PerformanceController
{
    public function __construct(private readonly StrategicPlanningService $planning, private readonly OrganizationScopeService $scope) {}

    public function index(Request $request): Response
    {
        $this->ensureEnabled();
        $user = $request->user();
        abort_unless($user->can('strategic_goals.view'), 403);
        $cycleId = $request->string('cycle_id')->toString();
        $organizationId = $request->string('organization_id')->toString();
        $allowedOrganizationIds = $this->scope->allowedOrganizationIds($user);
        abort_if($organizationId !== '' && ! in_array($organizationId, $allowedOrganizationIds, true), 403);
        if ($cycleId !== '') {
            $cycle = PerformanceCycle::query()->whereKey($cycleId)
                ->where(fn ($query) => $query->whereNull('organization_id')->orWhereIn('organization_id', $allowedOrganizationIds))->firstOrFail();
            abort_if($organizationId !== '' && $cycle->organization_id !== null && $cycle->organization_id !== $organizationId, 404);
        }

        $goals = StrategicGoal::query()->with(['allocations.unit:id,name_en,name_am,organization_id'])->withCount(['objectives' => fn ($query) => $query->whereHas('plan', fn ($plan) => $plan->whereNotIn('status', ['SUPERSEDED', 'CLOSED']))])
            ->whereIn('organization_id', $allowedOrganizationIds)
            ->when($cycleId, fn ($q) => $q->where('cycle_id', $cycleId))
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->orderBy('sort_order')->orderBy('code')->paginate(50)->withQueryString();

        return Inertia::render('Performance/StrategicGoals/Index', [
            'goals' => $goals->through(fn (StrategicGoal $goal) => [
                ...$goal->only(['id', 'cycle_id', 'organization_id', 'code', 'name_en', 'name_am', 'description_en', 'description_am', 'weight_percent', 'is_shared', 'sort_order']),
                'status' => $goal->status->value,
                'readiness' => $this->planning->readiness($goal),
                'objectives_count' => $goal->objectives_count,
                'allocations' => $goal->allocations->map(fn ($row) => [
                    ...$row->only(['id', 'organization_unit_id', 'organization_contribution_percent', 'is_lead', 'notes']),
                    'allocation_type' => $row->allocation_type->value,
                    'unit' => $row->unit?->only(['id', 'name_en', 'name_am']),
                ])->all(),
            ]),
            'summary' => $cycleId !== '' && $organizationId !== '' ? $this->planning->organizationReadiness($cycleId, $organizationId) : null,
            'filters' => ['cycle_id' => $cycleId, 'organization_id' => $organizationId],
            'cycles' => PerformanceCycle::query()->whereNotIn('status', ['CLOSED', 'CANCELLED'])
                ->where(fn ($q) => $q->whereNull('organization_id')->orWhereIn('organization_id', $allowedOrganizationIds))
                ->orderByDesc('start_date')->get(['id', 'code', 'name_en', 'name_am', 'organization_id'])->toArray(),
            'organizations' => $this->scope->applyOrganizationScope(Organization::query(), $user, 'id')->orderBy('name_en')->limit(200)->get(['id', 'name_en', 'name_am'])->toArray(),
            'units' => OrganizationUnit::query()->whereIn('organization_id', $allowedOrganizationIds)
                ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))->orderBy('name_en')->limit(500)->get(['id', 'organization_id', 'name_en', 'name_am'])->toArray(),
            'allocationTypes' => GoalAllocationType::values(),
            'can' => [
                'create' => $user->can('strategic_goals.create'), 'update' => $user->can('strategic_goals.update'),
                'delete' => $user->can('strategic_goals.delete_draft'), 'allocate' => $user->can('strategic_goal_allocations.manage'),
                'approve' => $user->can('strategic_goals.approve'), 'publish' => $user->can('strategic_goals.publish'),
            ],
        ]);
    }

    public function store(SaveStrategicGoalRequest $request): RedirectResponse
    {
        $this->planning->createGoal($request->validated(), $request->user());

        return $this->saved();
    }

    public function update(SaveStrategicGoalRequest $request, StrategicGoal $strategicGoal): RedirectResponse
    {
        $this->planning->updateGoal($strategicGoal, $request->validated(), $request->user());

        return $this->saved();
    }

    public function destroy(Request $request, StrategicGoal $strategicGoal): RedirectResponse
    {
        $this->planning->deleteGoal($strategicGoal, $request->user());

        return $this->saved();
    }

    public function storeAllocation(SaveStrategicGoalAllocationRequest $request, StrategicGoal $strategicGoal): RedirectResponse
    {
        $this->planning->addAllocation($strategicGoal, $request->validated(), $request->user());

        return $this->saved();
    }

    public function updateAllocation(SaveStrategicGoalAllocationRequest $request, StrategicGoalAllocation $allocation): RedirectResponse
    {
        $this->planning->updateAllocation($allocation, $request->validated(), $request->user());

        return $this->saved();
    }

    public function destroyAllocation(Request $request, StrategicGoalAllocation $allocation): RedirectResponse
    {
        $this->planning->deleteAllocation($allocation, $request->user());

        return $this->saved();
    }

    public function transition(Request $request, StrategicGoal $strategicGoal): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(StrategicGoalStatus::class)]]);
        $this->planning->transition($strategicGoal, StrategicGoalStatus::from($data['status']), $request->user());

        return $this->saved();
    }

    public function replacePeriodTargets(SaveKpiPeriodTargetsRequest $request, KpiTarget $target): RedirectResponse
    {
        $this->planning->replacePeriodTargets($target, $request->validated('period_targets'), $request->user());

        return $this->saved();
    }
}
