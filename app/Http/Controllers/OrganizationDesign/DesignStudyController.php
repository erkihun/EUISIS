<?php

declare(strict_types=1);

namespace App\Http\Controllers\OrganizationDesign;

use App\Enums\DesignStudyType;
use App\Enums\OrganizationDesignStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationDesign\ProposalRequest;
use App\Http\Requests\OrganizationDesign\StudyRequest;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationDesignStatusHistory;
use App\Models\OrganizationDesignStudy;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\ResponsibilityType;
use App\Models\UnitDesignProposal;
use App\Services\OrganizationDesign\DesignStudyService;
use App\Services\OrganizationDesign\ResponsibilityService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Design studies, their proposals and the hand-over to the change-request pipeline. */
class DesignStudyController extends Controller
{
    public function __construct(private readonly DesignStudyService $studies, private readonly ResponsibilityService $responsibilities, private readonly OrganizationScopeService $scope) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', OrganizationDesignStudy::class);
        $user = $request->user();
        $query = OrganizationDesignStudy::query()->with(['organization:id,code,name_en,name_am', 'preparer:id,name'])->withCount('proposals')
            ->when($request->string('organization_id')->toString(), fn ($q, $id) => $q->where('organization_id', $id))
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('study_type')->toString(), fn ($q, $t) => $q->where('study_type', $t))
            ->when($request->string('search')->toString(), fn ($q, $search) => $q->where(fn ($n) => $n->where('reference_number', ci_like_operator(), "%{$search}%")->orWhere('title_en', ci_like_operator(), "%{$search}%")->orWhere('title_am', ci_like_operator(), "%{$search}%")))
            ->orderByDesc('created_at');
        $this->scope->applyOrganizationScope($query, $user);

        return Inertia::render('OrganizationDesign/Studies/Index', [
            'studies' => $query->paginate(25)->withQueryString()->through(fn (OrganizationDesignStudy $s) => $this->summary($s)),
            'filters' => $request->only(['organization_id', 'status', 'study_type', 'search']),
            'organizations' => $this->organizationOptions($user),
            'statuses' => [OrganizationDesignStatus::Draft->value, OrganizationDesignStatus::UnderReview->value, OrganizationDesignStatus::Approved->value, OrganizationDesignStatus::Cancelled->value],
            'studyTypes' => DesignStudyType::values(),
            'can' => ['create' => $user->can('create', OrganizationDesignStudy::class)],
        ]);
    }

    public function store(StudyRequest $request): RedirectResponse
    {
        $study = $this->studies->create($request->user(), $request->validated('organization_id'), $request->validated());

        return to_route('organization-design.studies.show', $study)->with('flash', ['message' => __('organization-design.study_created', ['reference' => $study->reference_number]), 'type' => 'success']);
    }

    public function show(Request $request, OrganizationDesignStudy $study): Response
    {
        $this->authorize('view', $study);
        $user = $request->user();
        $study->load(['organization:id,code,name_en,name_am', 'preparer:id,name', 'reviewer:id,name', 'approver:id,name', 'hierarchyVersion:id,version_name,status']);
        $editable = $study->isEditable();

        return Inertia::render('OrganizationDesign/Studies/Show', [
            'study' => [
                ...$this->summary($study),
                'reason' => $study->reason, 'scope_notes' => $study->scope_notes, 'hierarchy_version_id' => $study->hierarchy_version_id,
                'hierarchy_version' => $study->hierarchyVersion ? ['id' => $study->hierarchyVersion->id, 'version_name' => $study->hierarchyVersion->version_name] : null,
                'submitted_at' => $study->submitted_at?->toISOString(), 'reviewed_by' => $study->reviewer?->name, 'reviewed_at' => $study->reviewed_at?->toISOString(), 'review_comment' => $study->review_comment,
                'approved_by' => $study->approver?->name, 'approved_at' => $study->approved_at?->toISOString(),
            ],
            'proposals' => $this->studies->tree($study),
            'history' => OrganizationDesignStatusHistory::query()->where('entity_type', $study->getMorphClass())->where('entity_id', $study->getKey())->with('actor:id,name')->orderByDesc('created_at')->get()
                ->map(fn ($h) => ['from' => $h->from_status, 'to' => $h->to_status, 'actor' => $h->actor?->name, 'reason' => $h->reason, 'at' => $h->created_at->toISOString()]),
            'units' => OrganizationUnit::query()->where('organization_id', $study->organization_id)->whereIn('status', ['active', 'draft'])->orderBy('code')->limit(1000)->get(['id', 'code', 'name_en', 'name_am', 'parent_unit_id'])->map->only(['id', 'code', 'name_en', 'name_am', 'parent_unit_id']),
            'unitTypes' => OrganizationUnitType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name_en', 'name_am'])->map->only(['id', 'code', 'name_en', 'name_am']),
            'hierarchyVersions' => HierarchyVersion::query()->orderByDesc('created_at')->limit(50)->get(['id', 'version_name', 'status'])->map(fn ($v) => ['id' => $v->id, 'version_name' => $v->version_name, 'status' => $v->status instanceof \BackedEnum ? $v->status->value : $v->status]),
            'objects' => $this->responsibilities->objects($study->organization_id),
            'responsibilityTypes' => ResponsibilityType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('code')->get()->map->only(['id', 'code', 'name_en', 'name_am', 'is_exclusive', 'covers_object']),
            'proposalTypes' => UnitDesignProposal::TYPES,
            'studyTypes' => DesignStudyType::values(),
            'can' => [
                'update' => $editable && $user->can('update', $study),
                'submit' => $editable && $user->can('update', $study),
                'review' => $study->status === OrganizationDesignStatus::UnderReview && $user->can('review', $study),
                'approve' => $study->status === OrganizationDesignStatus::UnderReview && $user->can('approve', $study) && (int) $study->prepared_by !== (int) $user->getKey(),
                'cancel' => in_array($study->status, [OrganizationDesignStatus::Draft, OrganizationDesignStatus::UnderReview], true) && $user->can('update', $study),
                'generate' => $study->status === OrganizationDesignStatus::Approved && $user->can('update', $study) && $user->can('organizational-change-requests.create'),
            ],
        ]);
    }

    public function update(StudyRequest $request, OrganizationDesignStudy $study): RedirectResponse
    {
        $this->studies->update($request->user(), $study, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.study_updated'), 'type' => 'success']);
    }

    public function submit(Request $request, OrganizationDesignStudy $study): RedirectResponse
    {
        $this->authorize('update', $study);
        $this->studies->submit($request->user(), $study);

        return back()->with('flash', ['message' => __('organization-design.study_submitted'), 'type' => 'success']);
    }

    public function returnToDraft(Request $request, OrganizationDesignStudy $study): RedirectResponse
    {
        $this->authorize('review', $study);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $this->studies->returnToDraft($request->user(), $study, $data['reason'] ?? null);

        return back()->with('flash', ['message' => __('organization-design.study_returned'), 'type' => 'success']);
    }

    public function approve(Request $request, OrganizationDesignStudy $study): RedirectResponse
    {
        $this->authorize('approve', $study);
        $this->studies->approve($request->user(), $study);

        return back()->with('flash', ['message' => __('organization-design.study_approved'), 'type' => 'success']);
    }

    public function cancel(Request $request, OrganizationDesignStudy $study): RedirectResponse
    {
        $this->authorize('update', $study);
        $this->studies->cancel($request->user(), $study, $request->string('reason')->toString() ?: null);

        return to_route('organization-design.studies.index')->with('flash', ['message' => __('organization-design.study_cancelled'), 'type' => 'success']);
    }

    public function generate(Request $request, OrganizationDesignStudy $study): RedirectResponse
    {
        $this->authorize('update', $study);
        $result = $this->studies->generateChangeRequests($request->user(), $study);

        return back()->with('flash', ['message' => __('organization-design.study_generated', $result), 'type' => 'success']);
    }

    public function adopt(Request $request, OrganizationDesignStudy $study): RedirectResponse
    {
        $this->authorize('update', $study);
        $count = $this->studies->adoptImplemented($request->user(), $study);

        return back()->with('flash', ['message' => __('organization-design.study_adopted', ['count' => $count]), 'type' => 'success']);
    }

    // ── Proposals ────────────────────────────────────────────────────────

    public function storeProposal(ProposalRequest $request, OrganizationDesignStudy $study): RedirectResponse
    {
        $this->studies->createProposal($request->user(), $study, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.proposal_saved'), 'type' => 'success']);
    }

    public function updateProposal(ProposalRequest $request, UnitDesignProposal $proposal): RedirectResponse
    {
        $this->studies->updateProposal($request->user(), $proposal, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.proposal_saved'), 'type' => 'success']);
    }

    public function destroyProposal(Request $request, UnitDesignProposal $proposal): RedirectResponse
    {
        $this->authorize('update', $proposal->study);
        $this->studies->removeProposal($request->user(), $proposal);

        return back()->with('flash', ['message' => __('organization-design.proposal_removed'), 'type' => 'success']);
    }

    /** @return array<string, mixed> */
    private function summary(OrganizationDesignStudy $s): array
    {
        return [
            'id' => $s->id, 'reference_number' => $s->reference_number, 'title_en' => $s->title_en, 'title_am' => $s->title_am, 'study_type' => $s->study_type->value, 'status' => $s->status->value,
            'organization' => $s->organization ? $s->organization->only(['id', 'code', 'name_en', 'name_am']) : null, 'organization_id' => $s->organization_id,
            'prepared_by' => $s->preparer?->name, 'prepared_by_id' => $s->prepared_by, 'started_at' => $s->started_at?->toDateString(), 'target_effective_date' => $s->target_effective_date?->toDateString(),
            'proposals_count' => $s->proposals_count ?? null, 'created_at' => $s->created_at?->toISOString(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function organizationOptions($user): array
    {
        $query = Organization::query()->orderBy('name_en');
        $this->scope->applyOrganizationScope($query, $user, 'id');

        return $query->limit(500)->get(['id', 'code', 'name_en', 'name_am'])->map->only(['id', 'code', 'name_en', 'name_am'])->all();
    }
}
