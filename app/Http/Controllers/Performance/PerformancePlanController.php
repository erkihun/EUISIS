<?php

declare(strict_types=1);

namespace App\Http\Controllers\Performance;

use App\Enums\Performance\CascadeMode;
use App\Enums\Performance\ObjectiveType;
use App\Enums\Performance\PlanStatus;
use App\Enums\Performance\PlanType;
use App\Http\Requests\Performance\RecordKpiActualRequest;
use App\Http\Requests\Performance\SaveKpiTargetRequest;
use App\Http\Requests\Performance\SaveObjectiveRequest;
use App\Http\Requests\Performance\StorePerformancePlanRequest;
use App\Jobs\Performance\RecalculatePlanScore;
use App\Models\Kpi;
use App\Models\KpiTarget;
use App\Models\Organization;
use App\Models\PerformanceCascade;
use App\Models\PerformanceCycle;
use App\Models\PerformanceObjective;
use App\Models\PerformancePlan;
use App\Models\PerformancePlanScore;
use App\Models\PerformanceTargetAmendment;
use App\Models\StrategicGoal;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\Performance\EpmsAccess;
use App\Services\Performance\KpiActualService;
use App\Services\Performance\PerformancePlanService;
use App\Services\Performance\PerformancePresenter;
use App\Services\Performance\PlanCascadeService;
use App\Services\Performance\TargetAmendmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PerformancePlanController extends PerformanceController
{
    public function __construct(
        private readonly PerformancePlanService $plans,
        private readonly PlanCascadeService $cascades,
        private readonly KpiActualService $actuals,
        private readonly TargetAmendmentService $amendments,
        private readonly PerformancePresenter $presenter,
        private readonly OrganizationScopeService $scope,
        private readonly EpmsAccess $access,
    ) {}

    public function index(Request $request): Response
    {
        $this->ensureEnabled();
        $user = $request->user();
        abort_unless($user->can('performance_plans.view'), 403);

        $plans = $this->scope->applyOrganizationScope(PerformancePlan::query(), $user)
            ->with(['organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am', 'position:id,title_en,title_am', 'cycle:id,name_en,name_am'])
            ->when($request->query('cycle_id'), fn ($q, $v) => $q->where('cycle_id', $v))
            ->when($request->query('plan_type'), fn ($q, $v) => $q->where('plan_type', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when(! $request->boolean('history'), fn ($q) => $q->where('status', '!=', PlanStatus::Superseded->value))
            ->orderBy('plan_type')->orderByDesc('updated_at')->paginate(25)->withQueryString();

        return Inertia::render('Performance/Plans/Index', [
            'plans' => $plans->through(fn (PerformancePlan $p) => $this->presenter->planSummary($p)),
            'filters' => $request->only(['cycle_id', 'plan_type', 'status', 'history']),
            'cycles' => $this->openCycles($user),
            'types' => PlanType::values(),
            'statuses' => PlanStatus::values(),
            'publishedParents' => $this->scope->applyOrganizationScope(PerformancePlan::query(), $user)
                ->where('status', PlanStatus::Published->value)->whereIn('plan_type', [PlanType::Organization->value, PlanType::Unit->value])
                ->with(['organizationUnit:id,name_en,name_am'])->limit(200)->get()
                ->map(fn ($p) => ['id' => $p->getKey(), 'title' => $p->title, 'type' => $p->plan_type->value, 'cycle_id' => $p->cycle_id, 'organization_id' => $p->organization_id, 'unit' => $p->organizationUnit?->name_en]),
            'organizations' => $this->scope->applyOrganizationScope(Organization::query(), $user, 'id')->orderBy('name_en')->limit(200)->get(['id', 'name_en', 'name_am'])->toArray(),
            'can' => ['create' => $user->can('performance_plans.create')],
        ]);
    }

    public function store(StorePerformancePlanRequest $request): RedirectResponse
    {
        $this->ensureEnabled();
        $plan = $this->plans->create($request->validated(), $request->user());

        return to_route('performance.plans.show', $plan)->with('flash', ['message' => __('performance.saved'), 'type' => 'success']);
    }

    public function show(Request $request, PerformancePlan $plan): Response
    {
        $this->ensureEnabled();
        $user = $request->user();
        abort_unless($this->access->inScope($user, 'performance_plans.view', $plan->organization_id), 403);
        $plan->load(['organization', 'organizationUnit', 'position', 'cycle', 'parentPlan']);

        $objectives = $plan->objectives()->with(['targets.kpi', 'targets.periodTargets', 'parentObjective.plan'])->get();
        $cascadedParentIds = $objectives->pluck('parent_objective_id')->filter()->all();
        $score = PerformancePlanScore::query()->where('performance_plan_id', $plan->getKey())->latest('as_of')->first();

        return Inertia::render('Performance/Plans/Show', [
            'plan' => [
                ...$this->presenter->planSummary($plan),
                'change_reason' => $plan->change_reason,
                'return_reason' => $plan->return_reason,
                'parent' => $plan->parentPlan ? ['id' => $plan->parentPlan->getKey(), 'title' => $plan->parentPlan->title] : null,
                'supersedes_plan_id' => $plan->supersedes_plan_id,
                'effective_from' => $plan->effective_from?->toDateString(),
                'effective_to' => $plan->effective_to?->toDateString(),
                'editable' => PerformancePlanService::isEditable($plan),
            ],
            'objectives' => $objectives->map(fn (PerformanceObjective $o) => [
                ...$o->only(['id', 'strategic_goal_id', 'code', 'title_en', 'title_am', 'description_en', 'description_am', 'weight', 'absolute_weight_percent', 'local_weight_percent', 'priority', 'is_mandatory', 'status', 'rejection_reason']),
                'objective_type' => $o->objective_type->value,
                'cascade_mode' => $o->cascade_mode->value,
                'lineage' => $o->parentObjective ? ['code' => $o->parentObjective->code, 'title_en' => $o->parentObjective->title_en, 'plan' => $o->parentObjective->plan?->title] : null,
                'targets' => $o->targets->map(fn (KpiTarget $t) => [
                    ...$t->only(['id', 'target_value', 'target_numerator', 'target_denominator', 'baseline_value', 'weight', 'achievement_cap', 'tolerance', 'zero_score_deviation', 'version_no', 'parent_target_id']),
                    'period' => [$t->period_start->toDateString(), $t->period_end->toDateString()],
                    'period_targets' => $t->periodTargets->map(fn ($period) => $period->only(['period_type', 'period_number', 'target_value', 'target_numerator', 'target_denominator', 'is_cumulative']))->all(),
                    'kpi' => ['id' => $t->kpi_id, 'code' => $t->kpi->code, 'name_en' => $t->kpi->name_en, 'name_am' => $t->kpi->name_am, 'direction' => $t->kpi->direction->value, 'aggregation' => $t->kpi->aggregation_method->value, 'source' => $t->kpi->data_source_type->value, 'unit' => $t->kpi->unit_of_measure],
                ])->all(),
            ])->all(),
            // Parent objectives available to cascade into this plan.
            'parentObjectives' => $plan->parentPlan
                ? $plan->parentPlan->objectives()->where('status', 'ACTIVE')->get()->map(fn ($o) => [
                    ...$o->only(['id', 'code', 'title_en', 'title_am', 'weight', 'is_mandatory']),
                    'cascaded' => in_array($o->getKey(), $cascadedParentIds, true),
                ])->all()
                : [],
            'childPlans' => $plan->childPlans()->where('status', '!=', PlanStatus::Superseded->value)->with(['organizationUnit', 'position'])->get()->map(fn ($c) => $this->presenter->planSummary($c))->all(),
            'cascades' => PerformanceCascade::query()->where('parent_plan_id', $plan->getKey())->with(['childObjective:id,code,title_en,title_am', 'parentObjective:id,code', 'childPlan:id,title'])->get()
                ->map(fn ($c) => ['parent' => $c->parentObjective?->code, 'child_code' => $c->childObjective?->code, 'child_title_en' => $c->childObjective?->title_en, 'child_plan' => $c->childPlan?->title, 'type' => $c->cascade_type->value])->all(),
            'versions' => PerformancePlan::query()->where('lineage_key', $plan->lineage_key)->orderByDesc('version_no')->get(['id', 'version_no', 'status', 'published_at', 'change_reason'])->toArray(),
            'validation' => $plan->status === PlanStatus::Draft || $plan->status === PlanStatus::Approved ? $this->plans->validate($plan) : [],
            'score' => $score ? ['as_of' => $score->as_of->toDateString(), 'score' => $score->score, 'trace' => $score->trace_json] : null,
            'pendingAmendments' => PerformanceTargetAmendment::query()->where('subject_type', 'TARGET')->where('status', 'PENDING')
                ->whereIn('subject_id', KpiTarget::query()->where('performance_plan_id', $plan->getKey())->select('id'))->get()->toArray(),
            'kpis' => PerformancePlanService::isEditable($plan) ? Kpi::query()->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $plan->organization_id))
                ->orderBy('code')->limit(500)->get(['id', 'code', 'name_en', 'name_am', 'direction', 'unit_of_measure'])->toArray() : [],
            'parentTargets' => $plan->parentPlan ? KpiTarget::query()->where('performance_plan_id', $plan->parent_plan_id)->where('is_current', true)->with('kpi:id,code')->get()
                ->map(fn ($t) => ['id' => $t->getKey(), 'kpi_id' => $t->kpi_id, 'kpi_code' => $t->kpi->code])->all() : [],
            'strategicGoals' => $plan->plan_type === PlanType::Organization
                ? StrategicGoal::query()->where('cycle_id', $plan->cycle_id)->where('organization_id', $plan->organization_id)->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_am', 'weight_percent'])->toArray()
                : [],
            'options' => ['objective_types' => ObjectiveType::values(), 'cascade_modes' => array_values(array_diff(CascadeMode::values(), ['LOCAL_ONLY']))],
            'can' => [
                'edit' => PerformancePlanService::isEditable($plan) && ($user->can('performance_objectives.manage') || $user->can('performance_plans.update')),
                'submit' => $plan->status === PlanStatus::Draft && $user->can('performance_plans.update'),
                'review' => $plan->status === PlanStatus::UnderReview && $user->can('performance_plans.review'),
                'approve' => $plan->status === PlanStatus::UnderReview && $user->can('performance_plans.approve'),
                'publish' => $plan->status === PlanStatus::Approved && $user->can('performance_plans.publish'),
                'newVersion' => $plan->status === PlanStatus::Published && $user->can('performance_plans.update'),
                'enterActual' => $plan->status === PlanStatus::Published && $user->can('kpi_actuals.enter'),
                'amend' => $plan->status === PlanStatus::Published && $user->can('kpi_targets.manage'),
                'decideAmendment' => $user->can('performance_plans.approve'),
                // Same check as recalculate(): a queued roll-up for someone who may read the scores.
                'recalculate' => $plan->status === PlanStatus::Published && $this->access->inScope($user, 'performance_reports.view', $plan->organization_id),
            ],
        ]);
    }

    // ── Objectives & targets ─────────────────────────────────────────────

    public function storeObjective(SaveObjectiveRequest $request, PerformancePlan $plan): RedirectResponse
    {
        $this->plans->addObjective($plan, $request->validated(), $request->user());

        return $this->saved();
    }

    public function updateObjective(SaveObjectiveRequest $request, PerformanceObjective $objective): RedirectResponse
    {
        $this->plans->updateObjective($objective, $request->validated(), $request->user());

        return $this->saved();
    }

    public function destroyObjective(Request $request, PerformanceObjective $objective): RedirectResponse
    {
        $this->plans->removeObjective($objective, $request->user());

        return $this->saved();
    }

    public function storeTarget(SaveKpiTargetRequest $request, PerformanceObjective $objective): RedirectResponse
    {
        $this->plans->addTarget($objective, $request->validated(), $request->user());

        return $this->saved();
    }

    public function updateTarget(SaveKpiTargetRequest $request, KpiTarget $target): RedirectResponse
    {
        $this->plans->updateTarget($target, $request->validated(), $request->user());

        return $this->saved();
    }

    public function destroyTarget(Request $request, KpiTarget $target): RedirectResponse
    {
        $this->plans->removeTarget($target, $request->user());

        return $this->saved();
    }

    // ── Cascading ────────────────────────────────────────────────────────

    public function cascade(Request $request, PerformancePlan $plan): RedirectResponse
    {
        $data = $request->validate([
            'parent_objective_id' => ['required', 'uuid', 'exists:performance_objectives,id'],
            'mode' => ['required', Rule::in(['ACCEPT', 'CUSTOMIZE', 'SPLIT', 'CONTRIBUTE'])],
            'title_en' => ['nullable', 'string', 'max:500'],
            'title_am' => ['nullable', 'string', 'max:500'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'contribution_weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'copy_targets' => ['boolean'],
            'parts' => ['nullable', 'array', 'required_if:mode,SPLIT', 'max:20'],
            'parts.*.title_en' => ['required', 'string', 'max:500'],
            'parts.*.title_am' => ['nullable', 'string', 'max:500'],
            'parts.*.weight' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $parent = PerformanceObjective::query()->findOrFail($data['parent_objective_id']);
        $this->cascades->cascade($parent, $plan, CascadeMode::from($data['mode']), $data, $request->user());

        return $this->saved();
    }

    public function decline(Request $request, PerformancePlan $plan): RedirectResponse
    {
        $data = $request->validate(['parent_objective_id' => ['required', 'uuid', 'exists:performance_objectives,id'], 'reason' => ['required', 'string', 'max:2000']]);
        $this->cascades->decline(PerformanceObjective::query()->findOrFail($data['parent_objective_id']), $plan, $data['reason'], $request->user());

        return $this->saved();
    }

    // ── Workflow ─────────────────────────────────────────────────────────

    public function workflow(Request $request, PerformancePlan $plan, string $action): RedirectResponse
    {
        $this->ensureEnabled();
        $user = $request->user();

        match ($action) {
            'submit' => $this->plans->submit($plan, $user),
            'return' => $this->plans->returnToDraft($plan, (string) $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'], $user),
            'approve' => $this->plans->approve($plan, $user),
            'publish' => $this->plans->publish($plan, $user),
            default => abort(404),
        };

        return $this->saved();
    }

    public function newVersion(Request $request, PerformancePlan $plan): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $next = $this->plans->newVersion($plan, $data['reason'], $request->user());

        return to_route('performance.plans.show', $next)->with('flash', ['message' => __('performance.saved'), 'type' => 'success']);
    }

    // ── Measurement ──────────────────────────────────────────────────────

    public function recordActual(RecordKpiActualRequest $request, KpiTarget $target): RedirectResponse
    {
        $this->actuals->recordForTarget($target, $request->validated(), $request->user());

        return $this->saved();
    }

    public function recalculate(Request $request, PerformancePlan $plan): RedirectResponse
    {
        abort_unless($this->access->inScope($request->user(), 'performance_reports.view', $plan->organization_id), 403);
        // Organization-wide roll-ups can touch many rows: queued, never on page load.
        RecalculatePlanScore::dispatch($plan->getKey(), now()->toDateString());

        return $this->saved(__('performance.saved'));
    }

    public function requestAmendment(Request $request, KpiTarget $target): RedirectResponse
    {
        $data = $request->validate([
            'target_value' => ['nullable', 'numeric'], 'target_numerator' => ['nullable', 'numeric'], 'target_denominator' => ['nullable', 'numeric', 'not_in:0'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:100'], 'reason' => ['required', 'string', 'max:2000'], 'effective_date' => ['required', 'date_format:Y-m-d'],
        ]);
        $this->amendments->request($target, array_filter($data, fn ($v, $k) => $v !== null && ! in_array($k, ['reason', 'effective_date'], true), ARRAY_FILTER_USE_BOTH), $data['reason'], $data['effective_date'], $request->user());

        return $this->saved();
    }

    public function decideAmendment(Request $request, PerformanceTargetAmendment $amendment): RedirectResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean']]);
        $this->amendments->decide($amendment, (bool) $data['approve'], $request->user());

        return $this->saved();
    }

    /** @return list<array<string, mixed>> */
    private function openCycles($user): array
    {
        return PerformanceCycle::query()
            ->whereNotIn('status', ['CLOSED', 'CANCELLED'])
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhereIn('organization_id', $this->scope->allowedOrganizationIds($user)))
            ->orderByDesc('start_date')->get(['id', 'code', 'name_en', 'name_am', 'organization_id', 'status'])->toArray();
    }
}
