<?php

declare(strict_types=1);

namespace Modules\Performance\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Support\Math\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\DailyWork\Contracts\DailyWorkOutputs;
use Modules\Performance\Application\Services\EpmsAccess;
use Modules\Performance\Application\Services\PerformanceCycleService;
use Modules\Performance\Application\Services\PerformancePlanService;
use Modules\Performance\Application\Services\StrategyHierarchyService;
use Modules\Performance\Domain\Enums\PlanType;
use Modules\Performance\Domain\Enums\StrategicGoalStatus;
use Modules\Performance\Domain\Models\MainStrategy;
use Modules\Performance\Domain\Models\PerformanceCycle;
use Modules\Performance\Domain\Models\PerformanceObjective;
use Modules\Performance\Domain\Models\StrategicGoal;
use Modules\Performance\Domain\Models\Strategy;
use Modules\Performance\Http\Requests\SaveMainStrategyRequest;
use Modules\Performance\Http\Requests\SaveStrategyGoalRequest;

/**
 * Strategic overview, the main-strategy and strategic-goal lists, detail
 * pages and forms, laid out and ordered like the WLMS pages (ADR 0019).
 */
class StrategyHierarchyController extends PerformanceController
{
    public function __construct(
        private readonly StrategyHierarchyService $hierarchy,
        private readonly OrganizationScopeService $scope,
        private readonly EpmsAccess $access,
        private readonly DailyWorkOutputs $dailyWork,
    ) {}

    public function index(Request $request): Response
    {
        $this->ensureEnabled();
        $user = $request->user();
        abort_unless($user->can('strategic_goals.view'), 403);
        [$organizations, $cycles, $organizationId, $cycleId] = $this->context($request, $user);
        $mains = $this->mains($organizationId, $cycleId);

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
                        'activities_count' => $goal->objectives->sum(fn (PerformanceObjective $o) => $o->activities->count()),
                        'objectives_total' => Dec::str($this->objectivesTotal($goal), 2),
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
            'summary' => $organizationId === '' || $cycleId === '' ? null : [
                'main_count' => $mains->count(),
                'strategy_count' => $mains->sum(fn (MainStrategy $main) => $main->strategies->count()),
                'goal_count' => $goals->count(),
                'objective_count' => $goals->sum('objectives_count'),
                'activity_count' => $goals->sum('activities_count'),
                'main_total' => Dec::str($mainTotal, 2),
                'remaining' => Dec::str(Dec::hundred()->minus($mainTotal), 2),
            ],
            ...$this->listProps($user, $organizations, $cycles, $organizationId, $cycleId),
        ]);
    }

    // ── Main strategies ──────────────────────────────────────────────────

    public function mainIndex(Request $request): Response
    {
        $this->ensureEnabled();
        $user = $request->user();
        abort_unless($user->can('strategic_goals.view'), 403);
        [$organizations, $cycles, $organizationId, $cycleId] = $this->context($request, $user);
        $mains = $this->mains($organizationId, $cycleId);

        return Inertia::render('Performance/Strategy/MainStrategies', [
            'mains' => $mains->values()->map(fn (MainStrategy $main, int $index) => $this->mainRow($main, $index + 1))->all(),
            'summary' => $organizationId === '' || $cycleId === '' ? null : [
                'main_count' => $mains->count(),
                'strategy_count' => $mains->sum(fn (MainStrategy $main) => $main->strategies->count()),
                'main_total' => Dec::str(Dec::sum($mains->map(fn (MainStrategy $main) => Dec::of($main->weight_percent))), 2),
            ],
            ...$this->listProps($user, $organizations, $cycles, $organizationId, $cycleId),
        ]);
    }

    public function showMain(Request $request, MainStrategy $mainStrategy): Response
    {
        $this->ensureEnabled();
        $user = $request->user();
        $this->authorizeOrganization($user, 'strategic_goals.view', $mainStrategy->organization_id);
        $number = $this->mains($mainStrategy->organization_id, $mainStrategy->cycle_id)->search(fn (MainStrategy $m) => $m->is($mainStrategy));

        return Inertia::render('Performance/Strategy/MainStrategyShow', [
            'main' => $this->mainRow($mainStrategy->load('strategies.goals'), $number === false ? 0 : $number + 1),
            'can' => $this->can($user, $mainStrategy->organization_id, $mainStrategy->cycle_id),
            'context' => $this->contextNames($mainStrategy->organization_id, $mainStrategy->cycle_id),
        ]);
    }

    public function createMain(Request $request): Response
    {
        $this->ensureEnabled();
        [$organizationId, $cycleId] = $this->requireContext($request, $request->user(), 'strategic_goals.create');

        return $this->mainForm(null, $organizationId, $cycleId);
    }

    public function storeMain(SaveMainStrategyRequest $request): RedirectResponse
    {
        $this->ensureEnabled();
        $main = $this->hierarchy->saveMainStrategy(null, $request->validated(), $request->user());

        return $this->toList('performance.strategy.main.index', $main->cycle_id, $main->organization_id);
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

        return $this->toList('performance.strategy.main.index', $main->cycle_id, $main->organization_id);
    }

    public function destroyMain(Request $request, MainStrategy $mainStrategy): RedirectResponse
    {
        $this->ensureEnabled();
        $this->hierarchy->deleteMainStrategy($mainStrategy, $request->user());

        return $this->toList('performance.strategy.main.index', $mainStrategy->cycle_id, $mainStrategy->organization_id);
    }

    // ── Strategic goals ──────────────────────────────────────────────────

    public function goalIndex(Request $request): Response
    {
        $this->ensureEnabled();
        $user = $request->user();
        abort_unless($user->can('strategic_goals.view'), 403);
        [$organizations, $cycles, $organizationId, $cycleId] = $this->context($request, $user);
        $mains = $this->mains($organizationId, $cycleId);

        $groups = $mains->values()->map(fn (MainStrategy $main, int $index) => [
            'id' => $main->getKey(),
            'number' => $index + 1,
            'title' => $main->title,
            'weight_percent' => $main->weight_percent,
            'goals' => $main->strategies->filter(fn (Strategy $s) => $s->goals->isNotEmpty())
                ->map(fn (Strategy $s) => $this->goalRow($s->goals->first(), $s, $index + 1))->values()->all(),
        ])->filter(fn (array $group) => $group['goals'] !== [])->values();
        $goals = $groups->flatMap(fn (array $group) => $group['goals']);

        return Inertia::render('Performance/Strategy/Goals', [
            'groups' => $groups->all(),
            'summary' => $organizationId === '' || $cycleId === '' ? null : [
                'goal_count' => $goals->count(),
                'active_count' => $goals->where('is_active', true)->count(),
                'inactive_count' => $goals->where('is_active', false)->count(),
                'objective_count' => $goals->sum(fn (array $goal) => count($goal['objectives'])),
                'activity_count' => $goals->sum('activities_count'),
            ],
            ...$this->listProps($user, $organizations, $cycles, $organizationId, $cycleId),
        ]);
    }

    public function showGoal(Request $request, StrategicGoal $strategicGoal): Response
    {
        $this->ensureEnabled();
        abort_if($strategicGoal->strategy_id === null, 404);
        $user = $request->user();
        $this->authorizeOrganization($user, 'strategic_goals.view', $strategicGoal->organization_id);
        $strategy = $strategicGoal->strategy()->with('mainStrategy')->firstOrFail();
        $mainNumber = $this->mains($strategicGoal->organization_id, $strategicGoal->cycle_id)->search(fn (MainStrategy $m) => $m->is($strategy->mainStrategy));
        $strategicGoal->setRelation('objectives', $this->liveObjectives($strategicGoal));

        return Inertia::render('Performance/Strategy/GoalShow', [
            'goal' => [
                ...$this->goalRow($strategicGoal, $strategy, $mainNumber === false ? 0 : $mainNumber + 1),
                'main_title' => $strategy->mainStrategy->title,
                'main_weight' => $strategy->mainStrategy->weight_percent,
            ],
            'can' => $this->can($user, $strategicGoal->organization_id, $strategicGoal->cycle_id),
            'context' => $this->contextNames($strategicGoal->organization_id, $strategicGoal->cycle_id),
        ]);
    }

    public function createGoal(Request $request): Response
    {
        $this->ensureEnabled();
        [$organizationId, $cycleId] = $this->requireContext($request, $request->user(), 'strategic_goals.create');

        return $this->goalForm(null, $organizationId, $cycleId, $request->string('strategy_id')->toString() ?: null);
    }

    public function storeGoal(SaveStrategyGoalRequest $request): RedirectResponse
    {
        $this->ensureEnabled();
        $goal = $this->hierarchy->saveGoal(null, $request->validated(), $request->user());

        return $this->toList('performance.strategy.goals.index', $goal->cycle_id, $goal->organization_id);
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

        return $this->toList('performance.strategy.goals.index', $goal->cycle_id, $goal->organization_id);
    }

    public function destroyGoal(Request $request, StrategicGoal $strategicGoal): RedirectResponse
    {
        $this->ensureEnabled();
        abort_if($strategicGoal->strategy_id === null, 404);
        $this->hierarchy->deleteGoal($strategicGoal, $request->user());

        return $this->toList('performance.strategy.goals.index', $strategicGoal->cycle_id, $strategicGoal->organization_id);
    }

    // ── Rows ─────────────────────────────────────────────────────────────

    /** Main strategies of one organization's cycle, in their WLMS numbering order, with goals in force. */
    private function mains(string $organizationId, string $cycleId): Collection
    {
        if ($organizationId === '' || $cycleId === '') {
            return new Collection;
        }

        $mains = MainStrategy::query()->where('cycle_id', $cycleId)->where('organization_id', $organizationId)
            ->with(['strategies.goals' => fn ($query) => $query->where('status', '!=', StrategicGoalStatus::Superseded->value)->orderByDesc('version_no')])
            ->orderBy('sort_order')->orderBy('created_at')->get();
        foreach ($mains as $main) {
            foreach ($main->strategies as $strategy) {
                $goal = $strategy->goals->first();
                $goal?->setRelation('objectives', $this->liveObjectives($goal));
            }
        }

        return $mains;
    }

    /**
     * The goal's objectives in the organization plan that is in force, with
     * their activities. Unit and position plans carry cascaded copies that
     * point at the same goal; counting them would double the goal's weight.
     */
    private function liveObjectives(StrategicGoal $goal): Collection
    {
        return $goal->objectives()->where('status', 'ACTIVE')
            ->whereHas('plan', fn ($plan) => $plan->where('plan_type', PlanType::Organization->value)->whereNotIn('status', ['SUPERSEDED', 'CLOSED']))
            ->with('activities')->orderBy('sort_order')->get();
    }

    private function objectivesTotal(StrategicGoal $goal): BigDecimal
    {
        return Dec::sum($goal->objectives->map(fn (PerformanceObjective $o) => Dec::of($o->absolute_weight_percent ?? $o->weight)));
    }

    /** @return array<string, mixed> a main strategy as the WLMS list and detail pages show it */
    private function mainRow(MainStrategy $main, int $number): array
    {
        $total = Dec::str(Dec::sum($main->strategies->map(fn (Strategy $s) => Dec::of($s->weight_percent))), 2);
        $normalized = StrategyHierarchyService::share($total, $main->weight_percent);

        return [
            ...$main->only(['id', 'title', 'description', 'weight_percent']),
            'number' => $number,
            'strategies' => $main->strategies->map(fn (Strategy $strategy) => [
                ...$strategy->only(['id', 'title', 'description', 'weight_percent']),
                'order_no' => $strategy->sort_order,
                'normalized' => StrategyHierarchyService::share($strategy->weight_percent, $main->weight_percent),
                'has_goal' => $strategy->goals->isNotEmpty(),
            ])->all(),
            'strategies_total' => $total,
            'normalized_total' => $normalized,
            'balanced' => $normalized !== null && Dec::of($normalized)->isEqualTo(100),
        ];
    }

    /** @return array<string, mixed> a strategic goal as the WLMS list and detail pages show it */
    private function goalRow(StrategicGoal $goal, Strategy $strategy, int $mainNumber): array
    {
        $serial = $mainNumber.'.'.$strategy->sort_order;
        $total = Dec::str($this->objectivesTotal($goal), 2);
        $normalized = StrategyHierarchyService::share($total, $strategy->weight_percent);
        $progress = $this->dailyWork->activityProgress(
            $goal->objectives->flatMap(fn (PerformanceObjective $o) => $o->activities->pluck('id'))->map(fn ($id) => (string) $id)->values()->all(),
            $goal->organization_id,
        );
        // An objective is as far along as the weight of its activities marked completed in daily work.
        $objectiveProgress = $goal->objectives->mapWithKeys(fn (PerformanceObjective $o) => [$o->getKey() => Dec::sum($o->activities
            ->filter(fn ($a) => $progress[(string) $a->id]['completed'] ?? false)->map(fn ($a) => Dec::of($a->weight_percent)))]);
        $goalProgress = Dec::div(Dec::sum($goal->objectives->map(fn (PerformanceObjective $o) => Dec::of($o->absolute_weight_percent ?? $o->weight)
            ->multipliedBy($objectiveProgress[$o->getKey()]))), $this->objectivesTotal($goal));

        return [
            'progress' => Dec::str($goalProgress ?? Dec::zero(), 2),
            'id' => $goal->getKey(),
            'code' => $goal->code,
            'serial' => $serial,
            'strategy_title' => $strategy->title,
            'strategy_weight' => $strategy->weight_percent,
            'description' => $goal->description_am,
            'is_active' => (bool) $goal->is_active,
            'status' => $goal->status->value,
            'objectives' => $goal->objectives->values()->map(fn (PerformanceObjective $objective, int $index) => [
                'id' => $objective->getKey(),
                'serial' => $serial.'.'.($index + 1),
                'title' => $objective->title_am ?: $objective->title_en,
                'weight_percent' => $objective->absolute_weight_percent ?? $objective->weight,
                'normalized' => StrategyHierarchyService::share((string) ($objective->absolute_weight_percent ?? $objective->weight), $strategy->weight_percent),
                'activities_total' => Dec::str(Dec::sum($objective->activities->map(fn ($a) => Dec::of($a->weight_percent))), 2),
                'progress' => Dec::str($objectiveProgress[$objective->getKey()], 2),
                'planned_minutes' => (int) $objective->activities->sum('time_minutes'),
                'spent_minutes' => (int) $objective->activities->sum(fn ($a) => $progress[(string) $a->id]['minutes'] ?? 0),
                'activities' => $objective->activities->values()->map(fn ($activity, int $a) => [
                    ...$activity->only(['id', 'title', 'weight_percent', 'time_minutes']),
                    'serial' => $serial.'.'.($index + 1).'.'.($a + 1),
                    // Work recorded against it in final daily logs.
                    'spent_minutes' => $progress[(string) $activity->id]['minutes'] ?? 0,
                    'entries' => $progress[(string) $activity->id]['entries'] ?? 0,
                    'completed' => $progress[(string) $activity->id]['completed'] ?? false,
                ])->all(),
            ])->all(),
            'activities_count' => $goal->objectives->sum(fn (PerformanceObjective $o) => $o->activities->count()),
            'objectives_total' => $total,
            'normalized_total' => $normalized,
            'balanced' => $normalized !== null && Dec::of($normalized)->isEqualTo(100),
        ];
    }

    // ── Forms ────────────────────────────────────────────────────────────

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
                'is_active' => (bool) $goal->is_active,
                'status' => $goal->status->value,
                'objectives' => $objectives->map(fn ($objective) => [
                    'id' => $objective->getKey(),
                    'title' => $objective->title_am ?: $objective->title_en,
                    'weight_percent' => $objective->absolute_weight_percent ?? $objective->weight,
                    'activities' => $objective->activities->map(fn ($activity) => $activity->only(['id', 'title', 'weight_percent', 'time_minutes']))->all(),
                ])->all(),
            ],
            'strategyId' => $strategyId,
            // As in WLMS, a strategy that already has a goal is not offered again.
            'groups' => $mains->values()->map(fn (MainStrategy $main, int $index) => [
                'id' => $main->getKey(),
                'number' => $index + 1,
                'title' => $main->title,
                'weight_percent' => $main->weight_percent,
                'strategies' => $main->strategies
                    ->filter(fn (Strategy $strategy) => $strategy->goals_count === 0 || $strategy->getKey() === $goal?->strategy_id)
                    ->map(fn (Strategy $strategy) => [
                        ...$strategy->only(['id', 'title', 'weight_percent']),
                        'order_no' => $strategy->sort_order,
                    ])->values()->all(),
            ])->all(),
            'plan' => $plan === null ? null : ['id' => $plan->getKey(), 'title' => $plan->title, 'status' => $plan->status->value],
            'editable' => $editable,
            'canPlan' => $canPlan,
            'context' => $this->contextNames($organizationId, $cycleId),
        ]);
    }

    // ── Context ──────────────────────────────────────────────────────────

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

    /** @return array<string, mixed> the filter and permission props every list page shares */
    private function listProps(User $user, Collection $organizations, Collection $cycles, string $organizationId, string $cycleId): array
    {
        return [
            'filters' => ['cycle_id' => $cycleId, 'organization_id' => $organizationId],
            'cycles' => $cycles->map(fn (PerformanceCycle $c) => [
                ...$c->only(['id', 'code', 'name_en', 'name_am', 'organization_id', 'is_current']),
                'status' => $c->status->value,
            ])->values()->all(),
            'organizations' => $organizations->toArray(),
            'can' => $this->can($user, $organizationId, $cycleId),
        ];
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

    private function toList(string $route, string $cycleId, string $organizationId): RedirectResponse
    {
        return to_route($route, ['cycle_id' => $cycleId, 'organization_id' => $organizationId])
            ->with('flash', ['message' => __('performance.saved'), 'type' => 'success']);
    }
}
