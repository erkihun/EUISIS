<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\OrganizationalChange\SaveChangeRequestAction;
use App\Actions\OrganizationalChange\TransitionChangeRequestAction;
use App\Enums\OrganizationalChangeAttachmentType;
use App\Enums\OrganizationalChangeRequestStatus;
use App\Enums\OrganizationalChangeRequestType;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationalChange\AssignImplementationRequest;
use App\Http\Requests\OrganizationalChange\ImplementOrganizationalChangeRequestRequest;
use App\Http\Requests\OrganizationalChange\ReviewOrganizationalChangeRequestRequest;
use App\Http\Requests\OrganizationalChange\StoreOrganizationalChangeAttachmentRequest;
use App\Http\Requests\OrganizationalChange\StoreOrganizationalChangeRequestRequest;
use App\Http\Requests\OrganizationalChange\UpdateOrganizationalChangeRequestRequest;
use App\Http\Resources\OrganizationalChangeRequestResource;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationalChangeRequest;
use App\Models\OrganizationalChangeRequestAttachment;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\User;
use App\Services\OrganizationalChange\ChangeRequestConflictDetector;
use App\Services\OrganizationalChange\ChangeRequestImpactAnalyzer;
use App\Services\OrganizationalChange\ChangeRequestScopeService;
use App\Services\OrganizationStructure\ApplyApprovedOrganizationalChangeService;
use App\Services\OrganizationStructure\Exceptions\ImplementationBlockedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The change-request workflow surface.
 *
 * Four queues, one record: My Requests, Review Queue, Approved Changes to
 * Implement, and Completed. Which queues a user sees is decided by permission,
 * and every query is narrowed by organization scope before it runs.
 */
class OrganizationalChangeRequestController extends Controller
{
    public function __construct(
        private readonly ChangeRequestScopeService $scope,
        private readonly ChangeRequestImpactAnalyzer $impact,
        private readonly ChangeRequestConflictDetector $conflicts,
    ) {}

    // ── Queues ──────────────────────────────────────────────────────────────

    /** My Requests: what this user has raised. */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', OrganizationalChangeRequest::class);

        $query = OrganizationalChangeRequest::query()
            ->where('requested_by', $request->user()->getKey());

        return Inertia::render('OrganizationalChangeRequests/Index', [
            'requests' => $this->paginate($request, $query),
            'filters' => $this->filters($request),
            'options' => $this->filterOptions($request),
            'can' => $this->abilities($request),
            'queue' => 'mine',
        ]);
    }

    /** Review queue: everything awaiting a decision inside the reviewer's scope. */
    public function reviewQueue(Request $request): Response
    {
        $this->authorize('viewAny', OrganizationalChangeRequest::class);
        abort_unless($request->user()->can('organizational-change-requests.review'), 403);

        $query = OrganizationalChangeRequest::query()->awaitingReview();

        return Inertia::render('OrganizationalChangeRequests/ReviewQueue', [
            'requests' => $this->paginate($request, $query),
            'filters' => $this->filters($request),
            'options' => $this->filterOptions($request),
            'can' => $this->abilities($request),
            'queue' => 'review',
        ]);
    }

    /** Approved Changes to Implement. */
    public function pendingImplementation(Request $request): Response
    {
        $this->authorize('viewApproved', OrganizationalChangeRequest::class);

        $query = OrganizationalChangeRequest::query()->awaitingImplementation();

        if ($request->filled('assigned_to')) {
            $query->where('implementation_assigned_to', $request->integer('assigned_to'));
        }

        return Inertia::render('OrganizationalChangeRequests/PendingImplementation', [
            'requests' => $this->paginate($request, $query),
            'filters' => $this->filters($request) + ['assigned_to' => $request->query('assigned_to', '')],
            'options' => $this->filterOptions($request),
            'can' => $this->abilities($request),
            'queue' => 'implementation',
        ]);
    }

    /** Completed and otherwise concluded requests. */
    public function completed(Request $request): Response
    {
        $this->authorize('viewAny', OrganizationalChangeRequest::class);

        $query = OrganizationalChangeRequest::query()->concluded();

        return Inertia::render('OrganizationalChangeRequests/Completed', [
            'requests' => $this->paginate($request, $query),
            'filters' => $this->filters($request),
            'options' => $this->filterOptions($request),
            'can' => $this->abilities($request),
            'queue' => 'completed',
        ]);
    }

    // ── Create / edit ───────────────────────────────────────────────────────

    public function create(Request $request): Response
    {
        $this->authorize('create', OrganizationalChangeRequest::class);

        return Inertia::render('OrganizationalChangeRequests/Create', [
            'options' => $this->formOptions($request),
            'allowedTypes' => $this->allowedTypes($request),
        ]);
    }

    public function store(StoreOrganizationalChangeRequestRequest $request, SaveChangeRequestAction $action): RedirectResponse
    {
        $changeRequest = $action->create($request->user(), $request->validated(), $request);

        return redirect()
            ->route('organizational-change-requests.show', $changeRequest)
            ->with('flash', ['message' => __('organizational-change-requests.flash.created', ['number' => $changeRequest->request_no]), 'type' => 'success']);
    }

    public function edit(Request $request, OrganizationalChangeRequest $organizationalChangeRequest): Response
    {
        $this->authorize('update', $organizationalChangeRequest);

        $organizationalChangeRequest->load(['items', 'organization', 'attachments.uploader', 'reviews.reviewer']);

        return Inertia::render('OrganizationalChangeRequests/Edit', [
            'request' => (new OrganizationalChangeRequestResource($organizationalChangeRequest))->resolve(),
            'options' => $this->formOptions($request, (string) $organizationalChangeRequest->organization_id),
            'allowedTypes' => $this->allowedTypes($request),
        ]);
    }

    public function update(
        UpdateOrganizationalChangeRequestRequest $request,
        OrganizationalChangeRequest $organizationalChangeRequest,
        SaveChangeRequestAction $action,
    ): RedirectResponse {
        $action->update($organizationalChangeRequest, $request->user(), $request->validated(), $request);

        return redirect()
            ->route('organizational-change-requests.show', $organizationalChangeRequest)
            ->with('flash', ['message' => __('organizational-change-requests.flash.updated'), 'type' => 'success']);
    }

    // ── Detail ──────────────────────────────────────────────────────────────

    public function show(Request $request, OrganizationalChangeRequest $organizationalChangeRequest): Response
    {
        // The IDOR gate: scope is checked against the loaded record, not
        // against anything the caller supplied.
        $this->authorize('view', $organizationalChangeRequest);

        $organizationalChangeRequest->load([
            'organization', 'requester', 'reviewer', 'approver', 'implementer',
            'implementationAssignee', 'implementingUnit',
            'items', 'reviews.reviewer', 'attachments.uploader', 'history.actor',
        ]);

        $user = $request->user();

        return Inertia::render('OrganizationalChangeRequests/Show', [
            'request' => (new OrganizationalChangeRequestResource($organizationalChangeRequest))->resolve(),
            // Recomputed live, so the reviewer sees today's numbers rather
            // than the ones captured when the request was written.
            'impact' => $this->impact->analyze($organizationalChangeRequest),
            'conflicts' => $organizationalChangeRequest->status->isAwaitingImplementation()
                ? $this->conflicts->detect($organizationalChangeRequest)
                : [],
            'can' => [
                'update' => $user->can('update', $organizationalChangeRequest),
                'submit' => $user->can('submit', $organizationalChangeRequest),
                'resubmit' => $user->can('resubmit', $organizationalChangeRequest),
                'cancel' => $user->can('cancel', $organizationalChangeRequest),
                'review' => $user->can('review', $organizationalChangeRequest),
                'requestCorrection' => $user->can('requestCorrection', $organizationalChangeRequest),
                'approve' => $user->can('approve', $organizationalChangeRequest),
                'reject' => $user->can('reject', $organizationalChangeRequest),
                'assignImplementation' => $user->can('assignImplementation', $organizationalChangeRequest),
                'implement' => $user->can('implement', $organizationalChangeRequest),
                'complete' => $user->can('complete', $organizationalChangeRequest),
                'returnForAmendment' => $user->can('returnForAmendment', $organizationalChangeRequest),
                'uploadAttachment' => $user->can('uploadAttachment', $organizationalChangeRequest),
            ],
        ]);
    }

    // ── Requester transitions ───────────────────────────────────────────────

    public function submit(Request $request, OrganizationalChangeRequest $organizationalChangeRequest, TransitionChangeRequestAction $action): RedirectResponse
    {
        $this->authorize('submit', $organizationalChangeRequest);
        $action->submit($organizationalChangeRequest, $request->user(), $request);

        return back()->with('flash', ['message' => __('organizational-change-requests.flash.submitted'), 'type' => 'success']);
    }

    public function resubmit(ReviewOrganizationalChangeRequestRequest $request, OrganizationalChangeRequest $organizationalChangeRequest, TransitionChangeRequestAction $action): RedirectResponse
    {
        $this->authorize('resubmit', $organizationalChangeRequest);
        $action->resubmit($organizationalChangeRequest, $request->user(), $request->validated('comment'), $request);

        return back()->with('flash', ['message' => __('organizational-change-requests.flash.resubmitted'), 'type' => 'success']);
    }

    public function cancel(ReviewOrganizationalChangeRequestRequest $request, OrganizationalChangeRequest $organizationalChangeRequest, TransitionChangeRequestAction $action): RedirectResponse
    {
        $this->authorize('cancel', $organizationalChangeRequest);
        $action->cancel($organizationalChangeRequest, $request->user(), $request->validated('comment'), $request);

        return back()->with('flash', ['message' => __('organizational-change-requests.flash.cancelled'), 'type' => 'success']);
    }

    // ── Reviewer transitions ────────────────────────────────────────────────

    public function startReview(Request $request, OrganizationalChangeRequest $organizationalChangeRequest, TransitionChangeRequestAction $action): RedirectResponse
    {
        $this->authorize('review', $organizationalChangeRequest);
        $action->startReview($organizationalChangeRequest, $request->user(), $request);

        return back()->with('flash', ['message' => __('organizational-change-requests.flash.review_started'), 'type' => 'success']);
    }

    public function requestCorrection(ReviewOrganizationalChangeRequestRequest $request, OrganizationalChangeRequest $organizationalChangeRequest, TransitionChangeRequestAction $action): RedirectResponse
    {
        $this->authorize('requestCorrection', $organizationalChangeRequest);
        $action->requestCorrection($organizationalChangeRequest, $request->user(), (string) $request->validated('comment'), $request);

        return back()->with('flash', ['message' => __('organizational-change-requests.flash.correction_requested'), 'type' => 'success']);
    }

    public function approve(ReviewOrganizationalChangeRequestRequest $request, OrganizationalChangeRequest $organizationalChangeRequest, TransitionChangeRequestAction $action): RedirectResponse
    {
        $this->authorize('approve', $organizationalChangeRequest);
        $action->approve($organizationalChangeRequest, $request->user(), $request->validated('comment'), $request);

        return back()->with('flash', ['message' => __('organizational-change-requests.flash.approved'), 'type' => 'success']);
    }

    public function reject(ReviewOrganizationalChangeRequestRequest $request, OrganizationalChangeRequest $organizationalChangeRequest, TransitionChangeRequestAction $action): RedirectResponse
    {
        $this->authorize('reject', $organizationalChangeRequest);
        $action->reject($organizationalChangeRequest, $request->user(), (string) $request->validated('comment'), $request);

        return back()->with('flash', ['message' => __('organizational-change-requests.flash.rejected'), 'type' => 'success']);
    }

    // ── Implementation ──────────────────────────────────────────────────────

    /** The implementation workspace: approved values, read-only. */
    public function implementation(Request $request, OrganizationalChangeRequest $organizationalChangeRequest): Response
    {
        $this->authorize('view', $organizationalChangeRequest);
        abort_unless($request->user()->can('organizational-change-requests.view_approved'), 403);

        $organizationalChangeRequest->load([
            'organization', 'requester', 'approver', 'implementingUnit', 'implementationAssignee',
            'items', 'reviews.reviewer', 'attachments.uploader', 'history.actor',
        ]);

        return Inertia::render('OrganizationalChangeRequests/Implement', [
            'request' => (new OrganizationalChangeRequestResource($organizationalChangeRequest))->resolve(),
            'impact' => $this->impact->analyze($organizationalChangeRequest),
            'conflicts' => $this->conflicts->detect($organizationalChangeRequest),
            'can' => [
                'implement' => $request->user()->can('implement', $organizationalChangeRequest),
                'complete' => $request->user()->can('complete', $organizationalChangeRequest),
                'returnForAmendment' => $request->user()->can('returnForAmendment', $organizationalChangeRequest),
                'assignImplementation' => $request->user()->can('assignImplementation', $organizationalChangeRequest),
            ],
        ]);
    }

    public function assignImplementation(AssignImplementationRequest $request, OrganizationalChangeRequest $organizationalChangeRequest, TransitionChangeRequestAction $action): RedirectResponse
    {
        $assignee = $request->validated('implementation_assigned_to');
        $unitId = $request->validated('implementing_unit_id');

        // An assignee must be able to reach the request's organization.
        if ($assignee !== null) {
            $user = User::query()->find($assignee);

            if ($user === null || ! $this->scope->canAccessOrganization($user, $organizationalChangeRequest->organization_id)) {
                throw ValidationException::withMessages([
                    'implementation_assigned_to' => __('organizational-change-requests.errors.assignee_out_of_scope'),
                ]);
            }
        }

        $action->assignImplementation($organizationalChangeRequest, $request->user(), $assignee !== null ? (int) $assignee : null, $unitId, $request);

        return back()->with('flash', ['message' => __('organizational-change-requests.flash.implementation_assigned'), 'type' => 'success']);
    }

    /** Apply Approved Change. */
    public function implement(
        ImplementOrganizationalChangeRequestRequest $request,
        OrganizationalChangeRequest $organizationalChangeRequest,
        ApplyApprovedOrganizationalChangeService $service,
    ): RedirectResponse {
        try {
            $service->execute($organizationalChangeRequest, $request->user(), $request->validated('note'), $request);
        } catch (ImplementationBlockedException $exception) {
            return back()->with('flash', [
                'message' => __('organizational-change-requests.flash.implementation_blocked', [
                    'count' => count($exception->conflicts),
                ]),
                'type' => 'error',
            ]);
        }

        return back()->with('flash', ['message' => __('organizational-change-requests.flash.implemented'), 'type' => 'success']);
    }

    public function complete(
        ReviewOrganizationalChangeRequestRequest $request,
        OrganizationalChangeRequest $organizationalChangeRequest,
        ApplyApprovedOrganizationalChangeService $service,
    ): RedirectResponse {
        $this->authorize('complete', $organizationalChangeRequest);
        $service->complete($organizationalChangeRequest, $request->user(), $request->validated('comment'), $request);

        return back()->with('flash', ['message' => __('organizational-change-requests.flash.completed'), 'type' => 'success']);
    }

    public function returnForAmendment(ReviewOrganizationalChangeRequestRequest $request, OrganizationalChangeRequest $organizationalChangeRequest, TransitionChangeRequestAction $action): RedirectResponse
    {
        $this->authorize('returnForAmendment', $organizationalChangeRequest);
        $action->returnForAmendment($organizationalChangeRequest, $request->user(), (string) $request->validated('comment'), $request);

        return back()->with('flash', ['message' => __('organizational-change-requests.flash.returned_for_amendment'), 'type' => 'success']);
    }

    // ── Attachments ─────────────────────────────────────────────────────────

    public function storeAttachment(StoreOrganizationalChangeAttachmentRequest $request, OrganizationalChangeRequest $organizationalChangeRequest): RedirectResponse
    {
        $config = config('organizational_change.attachments');
        $file = $request->file('file');

        $path = $file->store(
            $config['directory'].'/'.$organizationalChangeRequest->getKey(),
            $config['disk'],
        );

        OrganizationalChangeRequestAttachment::query()->create([
            'request_id' => $organizationalChangeRequest->getKey(),
            'document_type' => $request->validated('document_type'),
            'reference_no' => $request->validated('reference_no'),
            'document_date' => $request->validated('document_date'),
            'original_name' => $file->getClientOriginalName(),
            'file_name' => basename($path),
            'file_path' => $path,
            'file_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'uploaded_by' => $request->user()->getKey(),
        ]);

        return back()->with('flash', ['message' => __('organizational-change-requests.flash.attachment_uploaded'), 'type' => 'success']);
    }

    /** Private download: authorized against the parent request, never a public URL. */
    public function downloadAttachment(
        Request $request,
        OrganizationalChangeRequest $organizationalChangeRequest,
        OrganizationalChangeRequestAttachment $attachment,
    ): StreamedResponse {
        $this->authorize('view', $organizationalChangeRequest);

        abort_unless((string) $attachment->request_id === (string) $organizationalChangeRequest->getKey(), 404);

        $disk = Storage::disk(config('organizational_change.attachments.disk'));

        abort_unless($disk->exists($attachment->file_path), 404);

        return $disk->download($attachment->file_path, $attachment->original_name);
    }

    // ── Shared query helpers ────────────────────────────────────────────────

    /**
     * @param  Builder<OrganizationalChangeRequest>  $query
     * @return array<string, mixed>
     */
    private function paginate(Request $request, Builder $query): array
    {
        $this->scope->applyVisibilityScope($query, $request->user());

        $query->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('request_type'), fn (Builder $q) => $q->where('request_type', $request->string('request_type')->toString()))
            ->when($request->filled('organization_id'), fn (Builder $q) => $q->where('organization_id', $request->string('organization_id')->toString()))
            ->when($request->filled('effective_from'), fn (Builder $q) => $q->whereDate('requested_effective_date', '>=', $request->string('effective_from')->toString()))
            ->when($request->filled('effective_to'), fn (Builder $q) => $q->whereDate('requested_effective_date', '<=', $request->string('effective_to')->toString()))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('search')->toString()).'%';
                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('request_no', ci_like_operator(), $term)
                        ->orWhere('reason', ci_like_operator(), $term);
                });
            });

        $paginator = $query
            ->with(['organization:id,name_en,name_am,code', 'requester:id,name', 'implementationAssignee:id,name'])
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        return [
            'data' => OrganizationalChangeRequestResource::collection($paginator)->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ];
    }

    /** @return array<string, string> */
    private function filters(Request $request): array
    {
        return [
            'status' => $request->query('status', ''),
            'request_type' => $request->query('request_type', ''),
            'organization_id' => $request->query('organization_id', ''),
            'effective_from' => $request->query('effective_from', ''),
            'effective_to' => $request->query('effective_to', ''),
            'search' => $request->query('search', ''),
        ];
    }

    /** @return array<string, mixed> */
    private function filterOptions(Request $request): array
    {
        return [
            'statuses' => OrganizationalChangeRequestStatus::values(),
            'requestTypes' => OrganizationalChangeRequestType::values(),
            'organizations' => $this->scopedOrganizations($request),
        ];
    }

    /** @return array<string, mixed> */
    private function formOptions(Request $request, ?string $organizationId = null): array
    {
        $organizationIds = $this->scope->requestableOrganizationIds($request->user());
        $target = $organizationId ?? null;

        return [
            'organizations' => $this->scopedOrganizations($request),
            'unitTypes' => OrganizationUnitType::query()
                ->orderBy('name_en')
                ->get(['id', 'name_en', 'name_am', 'code']),
            'occupations' => Occupation::query()
                ->orderBy('name_en')
                ->limit(500)
                ->get(['id', 'name_en', 'name_am']),
            // Only units and positions inside the actor's scope are offered.
            'units' => OrganizationUnit::query()
                ->when($target !== null, fn (Builder $q) => $q->where('organization_id', $target))
                ->when($target === null, fn (Builder $q) => $q->whereIn('organization_id', $organizationIds))
                ->orderBy('name_en')
                ->limit(1000)
                ->get(['id', 'organization_id', 'parent_unit_id', 'name_en', 'name_am', 'code', 'status']),
            'positions' => Position::query()
                ->when($target !== null, fn (Builder $q) => $q->where('organization_id', $target))
                ->when($target === null, fn (Builder $q) => $q->whereIn('organization_id', $organizationIds))
                ->orderBy('title_en')
                ->limit(1000)
                ->get(['id', 'organization_id', 'organization_unit_id', 'title_en', 'title_am', 'job_position_code', 'grade_level', 'is_active']),
            'attachmentTypes' => OrganizationalChangeAttachmentType::values(),
        ];
    }

    private function scopedOrganizations(Request $request)
    {
        return Organization::query()
            ->whereIn('id', $this->scope->requestableOrganizationIds($request->user()))
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_am', 'code']);
    }

    /**
     * Request types this user may raise at all. Used to hide types they could
     * never submit; the server re-checks on store regardless.
     *
     * @return array<int, string>
     */
    private function allowedTypes(Request $request): array
    {
        $user = $request->user();

        return array_values(array_filter(
            OrganizationalChangeRequestType::values(),
            static fn (string $type): bool => $user->can(OrganizationalChangeRequestType::from($type)->requestPermission()),
        ));
    }

    /** @return array<string, bool> */
    private function abilities(Request $request): array
    {
        $user = $request->user();

        return [
            'create' => $user->can('create', OrganizationalChangeRequest::class),
            'review' => $user->can('organizational-change-requests.review'),
            'approve' => $user->can('organizational-change-requests.approve'),
            'viewApproved' => $user->can('organizational-change-requests.view_approved'),
            'implement' => $user->can('organizational-change-requests.implement'),
            'assignImplementation' => $user->can('organizational-change-requests.assign_implementation'),
        ];
    }
}
