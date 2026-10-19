<?php

declare(strict_types=1);

namespace App\Http\Controllers\OrganizationDesign;

use App\Enums\OrganizationDesignStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationDesign\StoreLegacyBaselineRequest;
use App\Http\Requests\OrganizationDesign\UpdateLegacyBaselineRequest;
use App\Models\HierarchyVersion;
use App\Models\LegacyBaseline;
use App\Models\LegacyBaselineStep;
use App\Models\LegalSource;
use App\Models\Organization;
use App\Models\OrganizationDesignStatusHistory;
use App\Models\OrganizationEdge;
use App\Services\OrganizationDesign\LegacyBaselineService;
use App\Services\OrganizationDesign\LegacyBaselineStepRegistry;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LegacyBaselineController extends Controller
{
    public function __construct(
        private readonly LegacyBaselineService $baselines,
        private readonly LegacyBaselineStepRegistry $steps,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', LegacyBaseline::class);
        $user = $request->user();

        $query = LegacyBaseline::query()
            ->with(['organization:id,code,name_en,name_am', 'preparer:id,name'])
            ->when($request->string('organization_id')->toString(), fn (Builder $q, $id) => $q->where('organization_id', $id))
            ->when($request->string('status')->toString(), fn (Builder $q, $status) => $q->where('status', $status))
            ->when($request->string('search')->toString(), function (Builder $q, $search): void {
                $q->where(fn (Builder $n) => $n->where('reference_number', ci_like_operator(), "%{$search}%")->orWhere('title_en', ci_like_operator(), "%{$search}%")->orWhere('title_am', ci_like_operator(), "%{$search}%"));
            })
            ->orderByDesc('created_at');
        $this->scope->applyOrganizationScope($query, $user);

        return Inertia::render('OrganizationDesign/LegacyBaselines/Index', [
            'baselines' => $query->paginate(25)->withQueryString()->through(fn (LegacyBaseline $b) => $this->summary($b)),
            'filters' => $request->only(['organization_id', 'status', 'search']),
            'organizations' => $this->organizationOptions($user),
            'statuses' => array_map(fn (OrganizationDesignStatus $s) => $s->value, OrganizationDesignStatus::cases()),
            'can' => ['create' => $user->can('create', LegacyBaseline::class)],
        ]);
    }

    public function store(StoreLegacyBaselineRequest $request): RedirectResponse
    {
        $baseline = $this->baselines->create($request->user(), $request->validated('organization_id'), $request->validated());

        return to_route('organization-design.legacy-baselines.show', $baseline)->with('flash', ['message' => __('organization-design.baseline_created'), 'type' => 'success']);
    }

    public function show(Request $request, LegacyBaseline $baseline): Response
    {
        $this->authorize('view', $baseline);
        $user = $request->user();
        $baseline->load(['organization:id,code,name_en,name_am', 'hierarchyVersion:id,version_name,status', 'legalSource:id,code,title_en,title_am', 'preparer:id,name', 'reviewer:id,name', 'approver:id,name', 'publisher:id,name', 'snapshot']);
        if ($baseline->status === OrganizationDesignStatus::Draft && $user->can('update', $baseline)) {
            $this->baselines->refreshSteps($baseline);
        }
        $steps = $baseline->steps()->get()->keyBy('step_key');
        $isPreparer = $baseline->prepared_by !== null && (int) $baseline->prepared_by === (int) $user->getKey();

        return Inertia::render('OrganizationDesign/LegacyBaselines/Show', [
            'baseline' => [
                ...$this->summary($baseline),
                'description' => $baseline->description,
                'hierarchy_version' => $baseline->hierarchyVersion ? ['id' => $baseline->hierarchyVersion->id, 'name' => $baseline->hierarchyVersion->version_name, 'status' => $baseline->hierarchyVersion->status] : null,
                'legal_source' => $baseline->legalSource ? ['id' => $baseline->legalSource->id, 'code' => $baseline->legalSource->code, 'title_en' => $baseline->legalSource->title_en, 'title_am' => $baseline->legalSource->title_am] : null,
                'review_comment' => $baseline->review_comment, 'approval_reference' => $baseline->approval_reference,
                'reviewed_by' => $baseline->reviewer?->name, 'approved_by' => $baseline->approver?->name, 'published_by' => $baseline->publisher?->name,
                'submitted_at' => $baseline->submitted_at?->toISOString(), 'approved_at' => $baseline->approved_at?->toISOString(), 'published_at' => $baseline->published_at?->toISOString(),
                'validation_summary' => $baseline->validation_summary, 'validated_at' => $baseline->validated_at?->toISOString(),
            ],
            'steps' => collect(LegacyBaselineStepRegistry::keys())->map(function (string $key) use ($steps, $baseline): array {
                /** @var LegacyBaselineStep|null $step */
                $step = $steps->get($key);

                return [
                    'key' => $key, 'status' => $step?->status ?? 'incomplete', 'status_source' => $step?->status_source ?? 'auto', 'record_count' => $step?->record_count ?? 0,
                    'notes' => $step?->notes, 'workflow' => in_array($key, LegacyBaselineStepRegistry::WORKFLOW_STEPS, true),
                    'entry_url' => $this->steps->entryUrl($key, $baseline->organization_id),
                ];
            })->values(),
            'validation' => $baseline->validated_at ? $this->baselines->validate(null, $baseline) : null,
            'snapshot' => $baseline->snapshot ? ['taken_at' => $baseline->snapshot->taken_at->toISOString(), 'counts' => $baseline->snapshot->counts, 'sha256' => $baseline->snapshot->sha256] : null,
            'versions' => $baseline->versions()->get(['id', 'version_no', 'reference_number', 'status', 'effective_date'])
                ->map(fn (LegacyBaseline $v) => ['id' => $v->id, 'version_no' => $v->version_no, 'reference_number' => $v->reference_number, 'status' => $v->status->value, 'effective_date' => $v->effective_date?->toDateString()]),
            'history' => OrganizationDesignStatusHistory::query()->where('entity_type', $baseline->getMorphClass())->where('entity_id', $baseline->getKey())
                ->with('actor:id,name')->orderByDesc('created_at')->get()
                ->map(fn ($h) => ['from' => $h->from_status, 'to' => $h->to_status, 'actor' => $h->actor?->name, 'reason' => $h->reason, 'at' => $h->created_at->toISOString()]),
            'hierarchyVersions' => $this->hierarchyVersionOptions($baseline->organization_id),
            'legalSources' => LegalSource::query()->citableBy($baseline->organization_id)->orderBy('code')->limit(300)->get(['id', 'code', 'title_en', 'title_am'])->map->only(['id', 'code', 'title_en', 'title_am']),
            'origins' => LegacyBaseline::ORIGINS,
            'stepStatuses' => LegacyBaselineStep::STATUSES,
            'can' => [
                'update' => $user->can('update', $baseline) && $baseline->status === OrganizationDesignStatus::Draft,
                'submit' => $user->can('update', $baseline) && $baseline->status === OrganizationDesignStatus::Draft,
                'return' => $user->can('review', $baseline) && $baseline->status === OrganizationDesignStatus::UnderReview,
                'approve' => $user->can('approve', $baseline) && $baseline->status === OrganizationDesignStatus::UnderReview && ! $isPreparer,
                'publish' => $user->can('publish', $baseline) && $baseline->status === OrganizationDesignStatus::Approved && ! $isPreparer,
                'revise' => $user->can('create', LegacyBaseline::class) && $this->scope->canAccess($user, $baseline->organization_id) && $baseline->status === OrganizationDesignStatus::Published,
                'cancel' => $user->can('update', $baseline) && in_array($baseline->status, [OrganizationDesignStatus::Draft, OrganizationDesignStatus::UnderReview], true),
                'validate' => $user->can('view', $baseline) && ! $baseline->status->isFinal(),
            ],
        ]);
    }

    public function update(UpdateLegacyBaselineRequest $request, LegacyBaseline $baseline): RedirectResponse
    {
        $this->baselines->update($request->user(), $baseline, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.baseline_updated'), 'type' => 'success']);
    }

    public function saveStep(Request $request, LegacyBaseline $baseline, string $step): RedirectResponse
    {
        $this->authorize('update', $baseline);
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', LegacyBaselineStep::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->baselines->saveStep($request->user(), $baseline, $step, $data['status'], $data['notes'] ?? null);

        return back()->with('flash', ['message' => __('organization-design.baseline_step_saved'), 'type' => 'success']);
    }

    public function validateBaseline(Request $request, LegacyBaseline $baseline): RedirectResponse
    {
        $this->authorize('view', $baseline);
        $report = $this->baselines->validate($request->user(), $baseline);

        return back()->with('flash', ['message' => __($report['ok'] ? 'organization-design.baseline_validation_ok' : 'organization-design.baseline_validation_failed', ['errors' => $report['summary']['errors'], 'warnings' => $report['summary']['warnings']]), 'type' => $report['ok'] ? 'success' : 'warning']);
    }

    public function submit(Request $request, LegacyBaseline $baseline): RedirectResponse
    {
        $this->authorize('update', $baseline);
        $this->baselines->submit($request->user(), $baseline);

        return back()->with('flash', ['message' => __('organization-design.baseline_submitted'), 'type' => 'success']);
    }

    public function return(Request $request, LegacyBaseline $baseline): RedirectResponse
    {
        $this->authorize('review', $baseline);
        $this->baselines->returnToDraft($request->user(), $baseline, $request->string('reason')->toString() ?: null);

        return back()->with('flash', ['message' => __('organization-design.baseline_returned'), 'type' => 'success']);
    }

    public function approve(Request $request, LegacyBaseline $baseline): RedirectResponse
    {
        $this->authorize('approve', $baseline);
        $data = $request->validate(['approval_reference' => ['nullable', 'string', 'max:255']]);
        $this->baselines->approve($request->user(), $baseline, $data['approval_reference'] ?? null);

        return back()->with('flash', ['message' => __('organization-design.baseline_approved'), 'type' => 'success']);
    }

    public function publish(Request $request, LegacyBaseline $baseline): RedirectResponse
    {
        $this->authorize('publish', $baseline);
        $this->baselines->publish($request->user(), $baseline);

        return back()->with('flash', ['message' => __('organization-design.baseline_published'), 'type' => 'success']);
    }

    public function revise(Request $request, LegacyBaseline $baseline): RedirectResponse
    {
        $this->authorize('create', LegacyBaseline::class);
        abort_unless($this->scope->canAccess($request->user(), $baseline->organization_id), 403);
        $draft = $this->baselines->newRevision($request->user(), $baseline);

        return to_route('organization-design.legacy-baselines.show', $draft)->with('flash', ['message' => __('organization-design.baseline_revision_created'), 'type' => 'success']);
    }

    public function cancel(Request $request, LegacyBaseline $baseline): RedirectResponse
    {
        $this->authorize('update', $baseline);
        $this->baselines->cancel($request->user(), $baseline, $request->string('reason')->toString() ?: null);

        return to_route('organization-design.legacy-baselines.index')->with('flash', ['message' => __('organization-design.baseline_cancelled'), 'type' => 'success']);
    }

    /** The frozen snapshot of a published baseline, for the organogram history and comparison. */
    public function snapshot(Request $request, LegacyBaseline $baseline): JsonResponse
    {
        $this->authorize('view', $baseline);
        $snapshot = $baseline->snapshot;
        abort_if($snapshot === null, 404);

        return response()->json([
            'baseline' => $this->summary($baseline),
            'taken_at' => $snapshot->taken_at->toISOString(),
            'counts' => $snapshot->counts,
            'units' => $snapshot->units,
            'relationships' => $snapshot->relationships,
            'positions' => $snapshot->positions,
            'establishments' => $snapshot->establishments,
            'sha256' => $snapshot->sha256,
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(LegacyBaseline $b): array
    {
        return [
            'id' => $b->id, 'reference_number' => $b->reference_number, 'version_no' => $b->version_no, 'lineage_key' => $b->lineage_key,
            'title_en' => $b->title_en, 'title_am' => $b->title_am, 'status' => $b->status->value, 'origin' => $b->origin,
            'effective_date' => $b->effective_date?->toDateString(),
            'validation_status' => $b->validation_status, 'mapping_required_count' => $b->mapping_required_count,
            'organization' => $b->organization ? ['id' => $b->organization->id, 'code' => $b->organization->code, 'name_en' => $b->organization->name_en, 'name_am' => $b->organization->name_am] : null,
            'organization_id' => $b->organization_id, 'hierarchy_version_id' => $b->hierarchy_version_id, 'legal_source_id' => $b->legal_source_id,
            'prepared_by' => $b->relationLoaded('preparer') ? $b->preparer?->name : null, 'prepared_by_id' => $b->prepared_by,
            'created_at' => $b->created_at?->toISOString(),
        ];
    }

    /** Hierarchy versions that place this organization, newest first. */
    private function hierarchyVersionOptions(string $organizationId): array
    {
        return HierarchyVersion::query()
            ->whereIn('id', OrganizationEdge::query()->where(fn ($q) => $q->where('parent_organization_id', $organizationId)->orWhere('child_organization_id', $organizationId))->select('hierarchy_version_id'))
            ->orderByDesc('effective_from')->limit(50)->get(['id', 'version_name', 'status', 'effective_from'])
            ->map(fn (HierarchyVersion $v) => ['id' => $v->id, 'name' => $v->version_name, 'status' => $v->status, 'effective_from' => $v->effective_from?->toDateString()])->all();
    }

    /** @return list<array{id: string, code: string, name_en: string, name_am: string|null}> */
    private function organizationOptions($user): array
    {
        $query = Organization::query()->orderBy('name_en');
        $this->scope->applyOrganizationScope($query, $user, 'id');

        return $query->limit(500)->get(['id', 'code', 'name_en', 'name_am'])->map->only(['id', 'code', 'name_en', 'name_am'])->all();
    }
}
