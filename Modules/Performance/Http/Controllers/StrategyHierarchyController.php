<?php

declare(strict_types=1);

namespace Modules\Performance\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Support\Math\Dec;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Performance\Application\Services\EpmsAccess;
use Modules\Performance\Application\Services\PerformanceCycleService;
use Modules\Performance\Application\Services\PerformancePlanService;
use Modules\Performance\Application\Services\StrategyHierarchyService;
use Modules\Performance\Domain\Enums\StrategicGoalStatus;
use Modules\Performance\Domain\Models\MainStrategy;
use Modules\Performance\Domain\Models\PerformanceCycle;
use Modules\Performance\Domain\Models\StrategicGoal;
use Modules\Performance\Domain\Models\Strategy;
use Modules\Performance\Http\Requests\SaveMainStrategyRequest;
use Modules\Performance\Http\Requests\SaveStrategyGoalRequest;

/**
 * Strategic overview, main strategies and the strategic-goal form with its
 * objectives and activities, entered the way the WLMS forms did (ADR 0019).
 */
class StrategyHierarchyController extends PerformanceController
{
    public function __construct(
        private readonly StrategyHierarchyService $hierarchy,
        private readonly OrganizationScopeService $scope,
        private readonly EpmsAccess $access,
    ) {}

    public function index(Request $request): Response
    {
        $this->ensureEnabled();
        $user = $request->user();
        abort_unless($user->can('strategic_goals.view'), 403);
        [$organizations, $cycles, $organizationId, $cycleId] = $this->context($request, $user);

        $mains = $organizationId !== '' && $cycleId !== ''
            ? MainStrategy::query()->where('cycle_id', $cycleId)->where('organization_id', $organizationId)
                ->with(['strategies.goals' => fn ($query) => $query->where('status', '!=', StrategicGoalStatus::Superseded->value)
                    ->orderByDesc('version_no')->with(['objectives' => fn ($objectives) => $objectives->where('status', 'ACTIVE')
                    ->whereHas('plan', fn ($plan) => $plan->whereNotIn('status', ['SUPERSEDED', 'CLOSED']))->withCount('activities')])])
                ->orderBy('sort_order')->orderBy('created_at')->get()
            : new Collection;

        $rows = $mains->values()->map(function (MainStrategy $main, int $index) {
            $strategies = $main->strategies->map(function (Strategy $strategy) use ($main, $index) {
                $goal = $strategy->goals->first();

                return [
                    ...$strategy->only(['id', 'title', 'description', 'weight_percent']),
                    'number' => ($index + 1).'.'.$strategy->sort_order,
                    'share_of_main' => StrategyHierarchyService::share($strategy->weight_percent, $main->weight_percent),
                    'goal' => $goal === null ? null : [
                        'id' => $goal->getKey(),
                        'code' => $goal->code,
                        'status' => $goal->status->value,
                        'objectives_count' => $goal->objectives->count(),
                        'activities_count' => (int) $goal->objectives->sum('activities_count'),
                        'objectives_total' => Dec::str(Dec::sum($goal->objectives->map(fn ($objective) => Dec::of($objective->absolute_weight_percent ?? $objective->weight))), 2),
                    ],
                ];
            });

            return [
                ...$main->only(['id', 'title', 'description', 'weight_percent']),
                'number' => $index + 1,
                'strategies_total' => Dec::str(Dec::sum($main->strategies->map(fn (Strategy $s) => Dec::of($s->weight_percent))), 2),
                'strategies' => $strategies->all(),
            ];
        });

        $goals = $rows->flatMap(fn (array $main) => $main['strategies'])->pluck('goal')->filter();
        $mainTotal = Dec::sum($mains->map(fn (MainStrategy $main) => Dec::of($main->weight_percent)));

        return Inertia::render('Performance/Strategy/Index', [
            'mains' => $rows->all(),
            'summary' => $mains->isEmpty() && $organizationId === '' ? null : [
                'main_count' => $mains->count(),
                'strategy_count' => $mains->sum(fn (MainStrategy $main) => $main->strategies->count()),
                'goal_count' => $goals->count(),
                'objective_count' => $goals->sum('objectives_count'),
                'activity_count' => $goals->sum('activities_count'),
                'main_total' => Dec::str($mainTotal, 2),
                'remaining' => Dec::str(Dec::hundred()->minus($mainTotal), 2),
            ],
            'filters' => ['cycle_id' => $cycleId, 'organization_id' => $organizationId],
            'cycles' => $this->cycleRows($cycles),
            'organizations' => $organizations->toArray(),
            'can' => $this->can($user, $organizationId, $cycleId),
        ]);
    }

    public function createMain(Request $request): Response
    {
        $this->ensureEnabled();
        $user = $request->user();
        [$organizationId, $cycleId] = $this->requireContext($request, $user, 'strategic_goals.create');

        return $this->mainForm(null, $organizationId, $cycleId);
    }

    public function storeMain(SaveMainStrategyRequest $request): RedirectResponse
    {
        $this->ensureEnabled();
        $main = $this->hierarchy->saveMainStrategy(null, $request->validated(), $request->user());

        return $this->toOverview($main->cycle_id, $main->organization_id);
    }

    public function editMain(Request $request, MainStrategy $mainStrategy): Response
    {
        $this->ensureEnabled();
        $this->authorizeOrganization($request->user(), 'strategic_goals.update', $mainStrategy->organization_id);

        return $this->mainForm($mainStrategy, $mainStrategy->organization_id, $mainStrategy->cycle_id);
    }

    public function updateMain(SaveMainStrategyRequest $request, MainStrategy $mainStrategy): RedirectResponse
    {
        $this->ensureEnabled();
        $main = $this->hierarchy->saveMainStrategy($mainStrategy, $request->validated(), $request->user());

        return $this->toOverview($main->cycle_id, $main->organization_id);
    }

    public function destroyMain(Request $request, MainStrategy $mainStrategy): RedirectResponse
    {
        $this->ensureEnabled();
        $this->hierarchy->deleteMainStrategy($mainStrategy, $request->user());

        return $this->saved();
    }

    public function createGoal(Request $request): Response
    {
        $this->ensureEnabled();
        $user = $request->user();
        [$organizationId, $cycleId] = $this->requireContext($request, $user, 'strategic_goals.create');

        return $this->goalForm(null, $organizationId, $cycleId, $request->string('strategy_id')->toString() ?: null);
    }

    public function storeGoal(SaveStrategyGoalRequest $request): RedirectResponse
    {
        $this->ensureEnabled();
        $goal = $this->hierarchy->saveGoal(null, $request->validated(), $request->user());

        return $this->toOverview($goal->cycle_id, $goal->organization_id);
    }

    public function editGoal(Request $request, StrategicGoal $strategicGoal): Response
    {
        $this->ensureEnabled();
        abort_if($strategicGoal->strategy_id === null, 404);
        $this->authorizeOrganization($request->user(), 'strategic_goals.view', $strategicGoal->organization_id);

        return $this->goalForm($strategicGoal, $strategicGoal->organization_id, $strategicGoal->cycle_id, $strategicGoal->strategy_id);
    }

    public function updateGoal(SaveStrategyGoalRequest $request, StrategicGoal $strategicGoal): RedirectResponse
    {
        $this->ensureEnabled();
        abort_if($strategicGoal->strategy_id === null, 404);
        $goal = $this->hierarchy->saveGoal($strategicGoal, $request->validated(), $request->user());

        return $this->toOverview($goal->cycle_id, $goal->organization_id);
    }

    private function mainForm(?MainStrategy $main, string $organizationId, string $cycleId): Response
    {
        $others = MainStrategy::query()->where('cycle_id', $cycleId)->where('organization_id', $organizationId)
            ->when($main !== null, fn ($query) => $query->whereKeyNot($main->getKey()))->pluck('weight_percent');
        $main?->load(['strategies.goals' => fn ($query) => $query->where('status', '!=', StrategicGoalStatus::Superseded->value)->orderByDesc('version_no')]);

        return Inertia::render('Performance/Strategy/MainStrategyForm', [
            'main' => $main === null ? null : [
                ...$main->only(['id', 'title', 'description', 'weight_percent']),
                'strategies' => $main->strategies->map(fn (Strategy $strategy) => [
                    ...$strategy->only(['id', 'title', 'description', 'weight_percent']),
                    'goal_status' => $strategy->goals->first()?->status->value,
                ])->all(),
            ],
            'remaining' => Dec::str(Dec::hundred()->minus(Dec::sum($others->map(fn ($weight) => Dec::of($weight)))), 2),
            'context' => $this->contextNames($organizationId, $cycleId),
        ]);
    }

    private function goalForm(?StrategicGoal $goal, string $organizationId, string $cycleId, ?string $strategyId): Response
    {
        $mains = MainStrategy::query()->where('cycle_id', $cycleId)->where('organization_id', $organizationId)
            ->with(['strategies' => fn ($query) => $query->withCount('goals')])->orderBy('sort_order')->orderBy('created_at')->get();
        $user = request()->user();
        $plan = $this->hierarchy->livePlan($cycleId, $organizationId);
        $objectives = $goal !== null && $plan !== null
            ? $plan->objectives()->where('strategic_goal_id', $goal->getKey())->where('status', 'ACTIVE')->with('activities')->orderBy('sort_order')->get()
            : new Collection;
        $canPlan = $this->hierarchy->canWritePlan($user, $cycleId, $organizationId);
        $editable = $canPlan && ($plan === null || PerformancePlanService::isEditable($plan)) && ($goal === null
            || ($goal->status === StrategicGoalStatus::Draft && ! PerformanceCycleService::isReadOnly($goal->cycle)
                && $this->access->inScope($user, 'strategic_goals.update', $goal->organization_id)));

        return Inertia::render('Performance/Strategy/GoalForm', [
            'goal' => $goal === null ? null : [
                ...$goal->only(['id', 'code', 'strategy_id', 'weight_percent']),
                'description' => $goal->description_am,
                'status' => $goal->status->value,
                'objectives' => $objectives->map(fn ($objective) => [
                    'id' => $objective->getKey(),
                    'title' => $objective->title_am ?: $objective->title_en,
                    'weight_percent' => $objective->absolute_weight_percent ?? $objective->weight,
                    'activities' => $objective->activities->map(fn ($activity) => $activity->only(['id', 'title', 'weight_percent', 'time_minutes']))->all(),
                ])->all(),
            ],
            'strategyId' => $strategyId,
            'groups' => $mains->values()->map(fn (MainStrategy $main, int $index) => [
                'id' => $main->getKey(),
                'number' => $index + 1,
                'title' => $main->title,
                'weight_percent' => $main->weight_percent,
                'strategies' => $main->strategies->map(fn (Strategy $strategy) => [
                    ...$strategy->only(['id', 'title', 'weight_percent']),
                    'number' => ($index + 1).'.'.$strategy->sort_order,
                    'taken' => $strategy->goals_count > 0 && $strategy->getKey() !== $goal?->strategy_id,
                ])->all(),
            ])->all(),
            'plan' => $plan === null ? null : ['id' => $plan->getKey(), 'title' => $plan->title, 'status' => $plan->status->value],
            'editable' => $editable,
            'canPlan' => $canPlan,
            'context' => $this->contextNames($organizationId, $cycleId),
        ]);
    }

    /** @return array{0: Collection<int, Organization>, 1: Collection<int, PerformanceCycle>, 2: string, 3: string} */
    private function context(Request $request, User $user): array
    {
        $allowed = $this->scope->allowedOrganizationIds($user);
        $organizations = $this->scope->applyOrganizationScope(Organization::query(), $user, 'id')->orderBy('name_en')->limit(200)->get(['id', 'name_en', 'name_am']);
        $cycles = PerformanceCycle::query()->whereNotIn('status', ['CLOSED', 'CANCELLED'])
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhereIn('organization_id', $allowed))
            ->orderByDesc('is_current')->orderByDesc('start_date')
            ->get(['id', 'code', 'name_en', 'name_am', 'organization_id', 'status', 'is_current', 'start_date', 'end_date']);

        // A first visit opens on the viewer's only organization and its current (or only) open cycle.
        $organizationId = $request->string('organization_id')->toString();
        if (! $request->has('organization_id') && $organizations->count() === 1) {
            $organizationId = (string) $organizations->first()->getKey();
        }
        $cycleId = $request->string('cycle_id')->toString();
        if (! $request->has('cycle_id') && $organizationId !== '') {
            $candidates = $cycles->filter(fn (PerformanceCycle $c) => $c->organization_id === null || $c->organization_id === $organizationId);
            $cycleId = (string) ($candidates->firstWhere('is_current', true) ?? ($candidates->count() === 1 ? $candidates->first() : null))?->getKey();
        }

        abort_if($organizationId !== '' && ! in_array($organizationId, $allowed, true), 403);
        if ($cycleId !== '') {
            $cycle = $cycles->firstWhere('id', $cycleId);
            abort_if($cycle === null || ($organizationId !== '' && $cycle->organization_id !== null && $cycle->organization_id !== $organizationId), 404);
        }

        return [$organizations, $cycles, $organizationId, $cycleId];
    }

    /** @return array{0: string, 1: string} */
    private function requireContext(Request $request, User $user, string $permission): array
    {
        $organizationId = $request->string('organization_id')->toString();
        $cycleId = $request->string('cycle_id')->toString();
        abort_if($organizationId === '' || $cycleId === '', 404);
        $this->authorizeOrganization($user, $permission, $organizationId);
        $cycle = PerformanceCycle::query()->findOrFail($cycleId);
        abort_if($cycle->organization_id !== null && $cycle->organization_id !== $organizationId, 404);

        return [$organizationId, $cycleId];
    }

    private function authorizeOrganization(User $user, string $permission, string $organizationId): void
    {
        abort_unless($this->access->inScope($user, $permission, $organizationId), 403);
    }

    /** @return array{create: bool, update: bool, delete: bool, plan: bool} */
    private function can(User $user, string $organizationId, string $cycleId): array
    {
        $open = $cycleId !== '' && $organizationId !== '' && ! PerformanceCycleService::isReadOnly(PerformanceCycle::query()->findOrFail($cycleId));

        return [
            'create' => $open && $this->access->inScope($user, 'strategic_goals.create', $organizationId),
            'update' => $open && $this->access->inScope($user, 'strategic_goals.update', $organizationId),
            'delete' => $open && $this->access->inScope($user, 'strategic_goals.delete_draft', $organizationId),
            // Goals carry their objectives into the organization plan, which needs plan oversight.
            'plan' => $open && $this->hierarchy->canWritePlan($user, $cycleId, $organizationId),
        ];
    }

    /** @return array{organization_id: string, cycle_id: string, organization: array<string, mixed>|null, cycle: array<string, mixed>|null} */
    private function contextNames(string $organizationId, string $cycleId): array
    {
        return [
            'organization_id' => $organizationId,
            'cycle_id' => $cycleId,
            'organization' => Organization::query()->find($organizationId, ['id', 'name_en', 'name_am'])?->toArray(),
            'cycle' => PerformanceCycle::query()->find($cycleId, ['id', 'code', 'name_en', 'name_am'])?->toArray(),
        ];
    }

    /** @param Collection<int, PerformanceCycle> $cycles */
    private function cycleRows(Collection $cycles): array
    {
        return $cycles->map(fn (PerformanceCycle $c) => [
            ...$c->only(['id', 'code', 'name_en', 'name_am', 'organization_id', 'is_current']),
            'status' => $c->status->value,
        ])->values()->all();
    }

    private function toOverview(string $cycleId, string $organizationId): RedirectResponse
    {
        return to_route('performance.strategy.index', ['cycle_id' => $cycleId, 'organization_id' => $organizationId])
            ->with('flash', ['message' => __('performance.saved'), 'type' => 'success']);
    }
}
