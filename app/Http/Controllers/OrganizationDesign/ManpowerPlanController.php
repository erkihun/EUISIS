<?php

declare(strict_types=1);

namespace App\Http\Controllers\OrganizationDesign;

use App\Enums\OrganizationDesignStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationDesign\ManpowerPlanLineRequest;
use App\Http\Requests\OrganizationDesign\ManpowerPlanRequest;
use App\Models\ManpowerPlan;
use App\Models\ManpowerPlanLine;
use App\Models\Organization;
use App\Models\OrganizationDesignStatusHistory;
use App\Models\OrganizationDesignStudy;
use App\Models\OrganizationUnit;
use App\Models\PositionRequirement;
use App\Models\UnitDesignProposal;
use App\Models\WorkforcePlanningMethodology;
use App\Services\OrganizationDesign\ManpowerCalculationService;
use App\Services\OrganizationDesign\PositionDesignService;
use App\Services\OrganizationDesign\WorkforcePlanningService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Manpower plans: lines, calculation, workflow, roll-up and implementation. */
class ManpowerPlanController extends Controller
{
    public function __construct(
        private readonly WorkforcePlanningService $planning,
        private readonly PositionDesignService $positions,
        private readonly ManpowerCalculationService $calculator,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ManpowerPlan::class);
        $user = $request->user();
        $query = ManpowerPlan::query()->with(['organization:id,code,name_en,name_am', 'methodology:id,code,name_en,name_am,version_no', 'preparer:id,name'])->withCount('lines')
            ->when($request->string('organization_id')->toString(), fn ($q, $id) => $q->where('organization_id', $id))
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('search')->toString(), fn ($q, $search) => $q->where(fn ($n) => $n->where('reference_number', ci_like_operator(), "%{$search}%")->orWhere('title_en', ci_like_operator(), "%{$search}%")->orWhere('title_am', ci_like_operator(), "%{$search}%")))
            ->orderByDesc('created_at');
        $this->scope->applyOrganizationScope($query, $user);

        return Inertia::render('OrganizationDesign/ManpowerPlans/Index', [
            'plans' => $query->paginate(25)->withQueryString()->through(fn (ManpowerPlan $p) => $this->summary($p)),
            'filters' => $request->only(['organization_id', 'status', 'search']),
            'organizations' => $this->organizationOptions($user),
            'methodologies' => $this->methodologyOptions(),
            'statuses' => [OrganizationDesignStatus::Draft->value, OrganizationDesignStatus::UnderReview->value, OrganizationDesignStatus::Approved->value, OrganizationDesignStatus::Cancelled->value],
            'can' => ['create' => $user->can('create', ManpowerPlan::class)],
        ]);
    }

    public function store(ManpowerPlanRequest $request): RedirectResponse
    {
        $plan = $this->planning->createPlan($request->user(), $request->validated('organization_id'), $request->validated());

        return to_route('organization-design.manpower-plans.show', $plan)->with('flash', ['message' => __('organization-design.plan_created', ['reference' => $plan->reference_number]), 'type' => 'success']);
    }

    public function show(Request $request, ManpowerPlan $plan): Response
    {
        $this->authorize('view', $plan);
        $user = $request->user();
        $plan->load(['organization:id,code,name_en,name_am', 'methodology', 'study:id,reference_number,title_en', 'preparer:id,name', 'reviewer:id,name', 'approver:id,name']);
        $editable = $plan->isEditable();
        $methodology = $plan->methodology->inForce();

        return Inertia::render('OrganizationDesign/ManpowerPlans/Show', [
            'plan' => [
                ...$this->summary($plan),
                'notes' => $plan->notes, 'hierarchy_version_id' => $plan->hierarchy_version_id, 'study_id' => $plan->study_id,
                'study' => $plan->study ? ['id' => $plan->study->id, 'reference_number' => $plan->study->reference_number, 'title_en' => $plan->study->title_en] : null,
                'submitted_at' => $plan->submitted_at?->toISOString(), 'reviewed_by' => $plan->reviewer?->name, 'review_comment' => $plan->review_comment,
                'approved_by' => $plan->approver?->name, 'approved_at' => $plan->approved_at?->toISOString(), 'implemented_at' => $plan->implemented_at?->toISOString(),
                'methodology_in_force' => ['id' => $methodology->id, 'code' => $methodology->code, 'version_no' => $methodology->version_no, 'calculation_type' => $methodology->calculation_type, 'rounding_rule' => $methodology->rounding_rule, 'parameters' => $methodology->parameters ?? [], 'inputs' => $this->calculator->strategy($methodology->calculation_type)->inputSchema()],
            ],
            'lines' => $plan->lines()->with(['unit:id,code,name_en,name_am', 'proposal:id,name_en,name_am', 'task:id,code,name_en,name_am', 'activity:id,code,name_en,name_am', 'occupation:id,code,isco_code,name_en,name_am', 'profession:id,code,name_en,name_am', 'methodology:id,code,version_no', 'adjuster:id,name'])->get()
                ->map(fn (ManpowerPlanLine $l) => [
                    ...$l->only(['id', 'organization_unit_id', 'unit_design_proposal_id', 'activity_id', 'task_id', 'occupation_id', 'profession_id', 'methodology_id', 'methodology_version_no', 'standard_id', 'inputs', 'recommended_headcount', 'approved_headcount', 'adjustment_reason', 'adjustment_reference', 'calculation_trace', 'notes', 'sequence', 'position_requirement_id', 'workload_source_type', 'workload_source_reference']),
                    'calculated_fte' => $l->calculated_fte, 'calculated_at' => $l->calculated_at?->toISOString(), 'headcount' => $l->headcount(),
                    'unit' => $l->unit?->only(['id', 'code', 'name_en', 'name_am']), 'proposal' => $l->proposal?->only(['id', 'name_en', 'name_am']),
                    'task' => $l->task?->only(['id', 'code', 'name_en', 'name_am']), 'activity' => $l->activity?->only(['id', 'code', 'name_en', 'name_am']),
                    'occupation' => $l->occupation?->only(['id', 'code', 'isco_code', 'name_en', 'name_am']), 'profession' => $l->profession?->only(['id', 'code', 'name_en', 'name_am']),
                    'methodology' => $l->methodology ? ['id' => $l->methodology->id, 'code' => $l->methodology->code, 'version_no' => $l->methodology->version_no] : null,
                    'adjusted_by' => $l->adjuster?->name,
                    'adjusted_at' => $l->adjusted_at?->toISOString(),
                ]),
            'requirements' => PositionRequirement::query()->where('plan_id', $plan->getKey())->with(['unit:id,code,name_en,name_am', 'proposal:id,name_en,name_am', 'occupation:id,code,isco_code,name_en,name_am', 'profession:id,code,name_en,name_am', 'position:id,job_position_code,title_en', 'establishment:id,establishment_number,approved_slots,status'])->orderBy('sequence')->get()
                ->map(fn (PositionRequirement $r) => [
                    ...$r->only(['id', 'organization_unit_id', 'unit_design_proposal_id', 'occupation_id', 'profession_id', 'position_title_en', 'position_title_am', 'grade_level', 'position_type', 'approved_headcount', 'status', 'position_id', 'establishment_id', 'sequence']),
                    'required_fte' => $r->required_fte, 'implemented_at' => $r->implemented_at?->toISOString(),
                    'unit' => $r->unit?->only(['id', 'code', 'name_en', 'name_am']), 'proposal' => $r->proposal?->only(['id', 'name_en', 'name_am']),
                    'occupation' => $r->occupation?->only(['id', 'code', 'isco_code', 'name_en', 'name_am']), 'profession' => $r->profession?->only(['id', 'code', 'name_en', 'name_am']),
                    'position' => $r->position?->only(['id', 'job_position_code', 'title_en']), 'establishment' => $r->establishment?->only(['id', 'establishment_number', 'approved_slots', 'status']),
                ]),
            'totals' => $this->planning->totals($plan),
            'validation' => $this->planning->validatePlan($plan),
            'history' => OrganizationDesignStatusHistory::query()->where('entity_type', $plan->getMorphClass())->where('entity_id', $plan->getKey())->with('actor:id,name')->orderByDesc('created_at')->get()
                ->map(fn ($h) => ['from' => $h->from_status, 'to' => $h->to_status, 'actor' => $h->actor?->name, 'reason' => $h->reason, 'at' => $h->created_at->toISOString()]),
            'units' => OrganizationUnit::query()->where('organization_id', $plan->organization_id)->whereIn('status', ['active', 'draft'])->orderBy('code')->limit(1000)->get(['id', 'code', 'name_en', 'name_am'])->map->only(['id', 'code', 'name_en', 'name_am']),
            'proposals' => $plan->study_id ? UnitDesignProposal::query()->where('study_id', $plan->study_id)->where('proposal_type', 'create')->orderBy('sequence')->get(['id', 'name_en', 'name_am', 'status'])->map->only(['id', 'name_en', 'name_am', 'status']) : [],
            // Tasks and occupations are searched on the server (organization-design.lookups), never shipped whole.
            'methodologies' => $this->methodologyOptions(),
            'studies' => OrganizationDesignStudy::query()->where('organization_id', $plan->organization_id)->whereIn('status', [OrganizationDesignStatus::Approved->value, OrganizationDesignStatus::UnderReview->value, OrganizationDesignStatus::Draft->value])->orderByDesc('created_at')->limit(50)->get(['id', 'reference_number', 'title_en', 'status'])->map(fn ($s) => ['id' => $s->id, 'reference_number' => $s->reference_number, 'title_en' => $s->title_en, 'status' => $s->status->value]),
            'can' => [
                'update' => $editable && $user->can('update', $plan),
                'submit' => $editable && $user->can('update', $plan),
                'review' => $plan->status === OrganizationDesignStatus::UnderReview && $user->can('review', $plan),
                'approve' => $plan->status === OrganizationDesignStatus::UnderReview && $user->can('approve', $plan) && (int) $plan->prepared_by !== (int) $user->getKey(),
                'cancel' => in_array($plan->status, [OrganizationDesignStatus::Draft, OrganizationDesignStatus::UnderReview], true) && $user->can('update', $plan),
                'implement' => $plan->status === OrganizationDesignStatus::Approved && $user->can('implement', $plan),
            ],
        ]);
    }

    public function update(ManpowerPlanRequest $request, ManpowerPlan $plan): RedirectResponse
    {
        $this->planning->updatePlan($request->user(), $plan, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.plan_updated'), 'type' => 'success']);
    }

    public function submit(Request $request, ManpowerPlan $plan): RedirectResponse
    {
        $this->authorize('update', $plan);
        $this->planning->submitPlan($request->user(), $plan);

        return back()->with('flash', ['message' => __('organization-design.plan_submitted'), 'type' => 'success']);
    }

    public function returnToDraft(Request $request, ManpowerPlan $plan): RedirectResponse
    {
        $this->authorize('review', $plan);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $this->planning->returnPlan($request->user(), $plan, $data['reason'] ?? null);

        return back()->with('flash', ['message' => __('organization-design.plan_returned'), 'type' => 'success']);
    }

    public function approve(Request $request, ManpowerPlan $plan): RedirectResponse
    {
        $this->authorize('approve', $plan);
        $this->planning->approvePlan($request->user(), $plan);

        return back()->with('flash', ['message' => __('organization-design.plan_approved'), 'type' => 'success']);
    }

    public function cancel(Request $request, ManpowerPlan $plan): RedirectResponse
    {
        $this->authorize('update', $plan);
        $this->planning->cancelPlan($request->user(), $plan, $request->string('reason')->toString() ?: null);

        return to_route('organization-design.manpower-plans.index')->with('flash', ['message' => __('organization-design.plan_cancelled'), 'type' => 'success']);
    }

    public function implement(Request $request, ManpowerPlan $plan): RedirectResponse
    {
        $this->authorize('implement', $plan);
        $result = $this->positions->implement($request->user(), $plan);

        return back()->with('flash', ['message' => __('organization-design.plan_implemented', $result), 'type' => 'success']);
    }

    public function calculateAll(Request $request, ManpowerPlan $plan): RedirectResponse
    {
        $this->authorize('update', $plan);
        $result = $this->planning->calculateAll($request->user(), $plan);

        return back()->with('flash', ['message' => __('organization-design.plan_calculated', ['calculated' => $result['calculated'], 'failed' => count($result['failed'])]), 'type' => $result['failed'] ? 'warning' : 'success']);
    }

    // ── Lines ────────────────────────────────────────────────────────────

    public function storeLine(ManpowerPlanLineRequest $request, ManpowerPlan $plan): RedirectResponse
    {
        $this->planning->createLine($request->user(), $plan, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.line_saved'), 'type' => 'success']);
    }

    public function updateLine(ManpowerPlanLineRequest $request, ManpowerPlanLine $line): RedirectResponse
    {
        $this->planning->updateLine($request->user(), $line, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.line_saved'), 'type' => 'success']);
    }

    public function destroyLine(Request $request, ManpowerPlanLine $line): RedirectResponse
    {
        $this->authorize('update', $line->plan);
        $this->planning->removeLine($request->user(), $line);

        return back()->with('flash', ['message' => __('organization-design.line_removed'), 'type' => 'success']);
    }

    public function calculateLine(Request $request, ManpowerPlanLine $line): RedirectResponse
    {
        $this->authorize('update', $line->plan);
        $line = $this->planning->calculateLine($request->user(), $line);

        return back()->with('flash', ['message' => __('organization-design.line_calculated', ['fte' => $line->calculated_fte, 'headcount' => $line->recommended_headcount ?? '—']), 'type' => 'success']);
    }

    /** @return array<string, mixed> */
    private function summary(ManpowerPlan $p): array
    {
        return [
            'id' => $p->id, 'reference_number' => $p->reference_number, 'title_en' => $p->title_en, 'title_am' => $p->title_am, 'status' => $p->status->value,
            'organization' => $p->organization?->only(['id', 'code', 'name_en', 'name_am']), 'organization_id' => $p->organization_id,
            'methodology_id' => $p->methodology_id, 'methodology' => $p->methodology ? ['id' => $p->methodology->id, 'code' => $p->methodology->code, 'name_en' => $p->methodology->name_en, 'name_am' => $p->methodology->name_am, 'version_no' => $p->methodology->version_no] : null,
            'planning_period_start' => $p->planning_period_start?->toDateString(), 'planning_period_end' => $p->planning_period_end?->toDateString(),
            'prepared_by' => $p->preparer?->name, 'prepared_by_id' => $p->prepared_by, 'lines_count' => $p->lines_count ?? null, 'created_at' => $p->created_at?->toISOString(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function methodologyOptions(): array
    {
        return WorkforcePlanningMethodology::query()->where('status', OrganizationDesignStatus::Published)->orderBy('code')->get(['id', 'code', 'name_en', 'name_am', 'version_no', 'calculation_type', 'rounding_rule'])
            ->map->only(['id', 'code', 'name_en', 'name_am', 'version_no', 'calculation_type', 'rounding_rule'])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function organizationOptions($user): array
    {
        $query = Organization::query()->orderBy('name_en');
        $this->scope->applyOrganizationScope($query, $user, 'id');

        return $query->limit(500)->get(['id', 'code', 'name_en', 'name_am'])->map->only(['id', 'code', 'name_en', 'name_am'])->all();
    }
}
