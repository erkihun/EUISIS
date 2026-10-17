<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\PublicFeedbackHandlerType;
use App\Enums\PublicFeedbackTargetType;
use App\Enums\PublicTipType;
use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use App\Http\Controllers\Controller;
use App\Models\EmployeeServiceFeedback;
use App\Models\EmployeeServiceFeedbackAttachment;
use App\Models\PublicFeedbackAssignment;
use App\Models\PublicFeedbackStatusHistory;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\ServiceFeedback\PublicEvidencePolicy;
use App\Services\ServiceFeedback\PublicFeedbackAssignmentService;
use App\Services\ServiceFeedback\PublicFeedbackQuestionnaire;
use App\Services\ServiceFeedback\PublicFeedbackReferralService;
use App\Services\ServiceFeedback\PublicFeedbackRoutingService;
use App\Services\ServiceFeedback\PublicFeedbackTransitionException;
use App\Services\ServiceFeedback\PublicFeedbackVisibility;
use App\Services\ServiceFeedback\PublicFeedbackWorkflow;
use App\Support\Storage\StoredFileChecksum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Permission-scoped inbox and workflow actions for the two non-rating public
 * submission types. Transitions and hand-overs go through the workflow and
 * assignment services so every change is recorded (brief §52–53); the
 * employee a submission is about never reaches any of it (§54).
 */
class ServiceSubmissionController extends Controller
{
    /** Status order for the inbox filter, per type. */
    private const STATUS_ORDER = [
        'suggestion' => ['pending', 'unrouted', 'assigned', 'under_review', 'accepted', 'not_adopted', 'reviewed', 'resolved', 'closed'],
        'complaint' => ['pending', 'unrouted', 'assigned', 'under_review', 'resolved', 'reviewed', 'closed'],
    ];

    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly WriteAuditLogAction $audit,
        private readonly PublicFeedbackWorkflow $workflow,
        private readonly PublicFeedbackAssignmentService $assignments,
        private readonly PublicFeedbackVisibility $visibility,
        private readonly PublicFeedbackReferralService $referrals,
    ) {}

    public function suggestions(Request $request): Response
    {
        return $this->index($request, 'suggestion');
    }

    public function complaints(Request $request): Response
    {
        return $this->index($request, 'complaint');
    }

    private function index(Request $request, string $type): Response
    {
        $kind = $this->kind($type);
        $user = $this->user();
        abort_unless($user->hasAnyPermission([$this->viewPermission($kind)]), 403);

        $query = $this->scoped($user, $kind);
        $status = $request->query('status');
        $status = is_string($status) && in_array($status, ServiceFeedbackStatus::values(), true) ? $status : null;
        if ($status !== null) {
            $query->where('status', $status);
        }
        // Tip-offs filter by their type; legacy rows without one are their own bucket.
        $tipType = $kind === ServiceFeedbackType::Suggestion ? $request->query('tip_type') : null;
        $tipType = is_string($tipType) && in_array($tipType, [...PublicTipType::values(), PublicTipType::UNCLASSIFIED_LEGACY], true) ? $tipType : null;
        if ($tipType !== null) {
            $tipType === PublicTipType::UNCLASSIFIED_LEGACY ? $query->whereNull('tip_type') : $query->where('tip_type', $tipType);
        }
        $mine = $request->boolean('mine');
        if ($mine) {
            $query->whereHas('currentAssignment', fn (Builder $q) => $this->handledBy($q, $user));
        }

        return Inertia::render('ServiceFeedback/SubmissionInbox', [
            'type' => $kind->value,
            'entries' => $query->with(['employee:id,full_name,employee_number', 'organization:id,name_en,name_am', 'organizationTarget:id,code,name_en,name_am', 'currentAssignment'])
                ->latest('created_at')->paginate(20)->withQueryString()
                ->through(fn (EmployeeServiceFeedback $entry): array => $this->summary($entry)),
            'counts' => $this->scoped($user, $kind)
                ->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status'),
            'statuses' => self::STATUS_ORDER[$kind->value],
            'filters' => ['status' => $status, 'mine' => $mine, 'tip_type' => $tipType],
            'canManageRouting' => $user->hasAnyPermission([PublicFeedbackRoutingService::PERMISSION]),
        ]);
    }

    public function show(Request $request, string $type, EmployeeServiceFeedback $submission): Response
    {
        $kind = $this->kind($type);
        abort_unless($submission->type === $kind, 404);
        $user = $this->user();
        $this->assertAccess($user, $submission);
        $this->authorize('view', $submission);

        // Seeing who sent it — above all a tipster — is a recorded act. Codes only, never the details.
        if ($this->visibility->canSeeIdentity($user) && $this->visibility->hasIdentity($submission)) {
            $this->audit->execute(
                eventType: AuditEventType::PublicSubmitterIdentityViewed,
                actor: $user,
                auditable: $submission,
                organizationId: $submission->organization_id,
                reason: 'Submitter identity viewed on public '.$kind->value,
                request: $request,
            );
        }

        $submission->load([
            'employee:id,full_name,employee_number',
            'organization:id,name_en,name_am',
            'organizationTarget:id,code,name_en,name_am',
            'organizationUnit:id,name_en,name_am',
            'position:id,title_en,title_am',
            'category:id,name_en,name_am',
            'attachments:id,employee_service_feedback_id,original_name,mime_type,size_bytes,scan_status',
            'statusHistory.actor:id,name',
            'assignments.assignedBy:id,name',
            'currentAssignment',
            'subjects.employee:id,full_name,employee_number',
            'subjects.addedBy:id,name',
            'referrals.requester:id,name',
            'referrals.decider:id,name',
        ]);
        $locale = app()->getLocale();

        $allowed = array_values(array_map(
            fn (ServiceFeedbackStatus $to): string => $to->value,
            array_filter(
                $this->workflow->allowedFrom($kind, $submission->status),
                fn (ServiceFeedbackStatus $to): bool => ! $to->isSystemManaged() && $user->can('transition', [$submission, $to]),
            ),
        ));
        $current = $submission->currentAssignment;
        $assignable = $user->can('assign', $submission)
            && in_array($submission->status, [ServiceFeedbackStatus::Pending, ServiceFeedbackStatus::Unrouted, ServiceFeedbackStatus::Assigned, ServiceFeedbackStatus::UnderReview], true)
            && $user->hasAnyPermission([$current === null ? 'service_feedback.assign' : 'service_feedback.reassign']);

        return Inertia::render('ServiceFeedback/SubmissionDetail', [
            'type' => $kind->value,
            'entry' => $this->summary($submission) + $this->visibility->identity($user, $submission) + [
                'subject' => $submission->subject,
                'involved_parties' => $submission->involved_parties,
                'intake_channel' => $submission->intake_channel?->value,
                'incident_date' => $submission->incident_date?->toDateString(),
                'incident_location' => $submission->incident_location,
                'organization_unit' => $locale === 'am' ? ($submission->organizationUnit?->name_am ?? $submission->organizationUnit?->name_en) : $submission->organizationUnit?->name_en,
                'position' => $locale === 'am' ? ($submission->position?->title_am ?? $submission->position?->title_en) : $submission->position?->title_en,
                'category' => $submission->category?->localizedName($locale),
                'review_note' => $submission->review_note,
                'acknowledged_at' => $submission->acknowledged_at?->toIso8601String(),
                'assigned_at' => $submission->assigned_at?->toIso8601String(),
                'resolved_at' => $submission->resolved_at?->toIso8601String(),
                'closed_at' => $submission->closed_at?->toIso8601String(),
                // Brief §66: evidence is a separate grant; everyone else sees only that files exist.
                'attachment_count' => $submission->attachments->count(),
                'attachments_hidden' => ! $this->visibility->canSeeAttachments($user),
                'attachments' => ! $this->visibility->canSeeAttachments($user) ? [] : $submission->attachments->map(fn ($file): array => [
                    'id' => $file->id,
                    'name' => $file->original_name,
                    'size' => $file->size_bytes,
                    // Submitted evidence, not verified proof; and never claimed scanned when it was not.
                    'kind' => PublicEvidencePolicy::kindForMime((string) $file->mime_type),
                    'scan_status' => $file->scan_status,
                ])->all(),
            ],
            'organizationForm' => app(PublicFeedbackQuestionnaire::class)->presentForStaff($submission, $locale),
            'history' => $submission->statusHistory->map(fn (PublicFeedbackStatusHistory $row): array => [
                'id' => $row->id,
                'from_status' => $row->from_status,
                'to_status' => $row->to_status,
                'actor' => $row->actor?->name,
                'reason' => $row->reason,
                'transitioned_at' => $row->transitioned_at?->toIso8601String(),
            ])->values()->all(),
            'assignments' => $submission->assignments->map(fn (PublicFeedbackAssignment $row): array => $this->assignmentRow($row, $locale))->values()->all(),
            'currentAssignment' => $current === null ? null : $this->assignmentRow($current, $locale),
            'allowedTransitions' => $allowed,
            'subjects' => $submission->subjects->map(fn ($subject): array => [
                'id' => $subject->id,
                'employee' => ['id' => $subject->employee_id, 'full_name' => $subject->employee?->full_name, 'employee_number' => $subject->employee?->employee_number],
                'subject_role' => $subject->subject_role,
                'added_by' => $subject->addedBy?->name,
                'added_at' => $subject->added_at?->toIso8601String(),
            ])->values()->all(),
            'referrals' => $submission->referrals->map(fn ($referral): array => [
                'id' => $referral->id,
                'kind' => $referral->kind,
                'status' => $referral->status,
                'notes' => $referral->notes,
                'requested_by' => $referral->requester?->name,
                'requested_at' => $referral->requested_at?->toIso8601String(),
                'decided_by' => $referral->decider?->name,
                'decided_at' => $referral->decided_at?->toIso8601String(),
                'decision_notes' => $referral->decision_notes,
                'disciplinary_case_id' => $referral->disciplinary_case_id,
                'mine' => (int) $referral->requested_by === (int) $user->getKey(),
            ])->values()->all(),
            'can' => [
                'assign' => $assignable,
                'subjects' => $kind === ServiceFeedbackType::Complaint && $user->can('manageSubjects', $submission),
                // Brief §59–60: asking for a referral, and deciding one, never by the employee involved.
                'refer' => $this->referrals->canRequest($user, $submission),
                'decideReferral' => $submission->referrals->contains(fn ($r) => $r->status === 'requested' && (int) $r->requested_by !== (int) $user->getKey()) && $this->referrals->canRefer($user, $submission),
            ],
            'handlerOptions' => $assignable ? $this->assignments->options($submission->organization_id) : null,
        ]);
    }

    /** Move the submission along its type's workflow (brief §48–51). */
    public function transition(Request $request, string $type, EmployeeServiceFeedback $submission): RedirectResponse
    {
        $kind = $this->kind($type);
        abort_unless($submission->type === $kind, 404);
        $user = $this->user();
        $this->assertAccess($user, $submission);

        $data = $request->validate([
            'status' => ['required', Rule::in(ServiceFeedbackStatus::values())],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $to = ServiceFeedbackStatus::from($data['status']);

        if ($to->isSystemManaged()) {
            throw ValidationException::withMessages(['status' => __('service-feedback.transition_not_allowed')]);
        }

        $this->authorize('transition', [$submission, $to]);

        try {
            $this->workflow->transition($submission, $to, $user, $data['reason'] ?? null, $request);
        } catch (PublicFeedbackTransitionException) {
            throw ValidationException::withMessages(['status' => __('service-feedback.transition_not_allowed')]);
        }

        return back()->with('success', __('service-feedback.status_changed'));
    }

    /** Hand the submission to a handler, or to another one (brief §53). */
    public function assign(Request $request, string $type, EmployeeServiceFeedback $submission): RedirectResponse
    {
        $kind = $this->kind($type);
        abort_unless($submission->type === $kind, 404);
        $user = $this->user();
        $this->assertAccess($user, $submission);
        $this->authorize('assign', $submission);

        $data = $request->validate([
            'handler_type' => ['required', Rule::in(PublicFeedbackHandlerType::values())],
            'handler_id' => ['required', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $hasHandler = $submission->currentAssignment()->exists();
        abort_unless($user->hasAnyPermission([$hasHandler ? 'service_feedback.reassign' : 'service_feedback.assign']), 403);

        $this->assignments->assign(
            $submission,
            PublicFeedbackHandlerType::from($data['handler_type']),
            (string) $data['handler_id'],
            $user,
            $data['reason'] ?? null,
            null,
            $request,
        );

        return back()->with('success', __('service-feedback.assigned'));
    }

    public function download(Request $request, string $type, EmployeeServiceFeedback $submission, EmployeeServiceFeedbackAttachment $attachment): StreamedResponse
    {
        // Evidence exists on Complaints and Tip-offs; the URL's type must be the row's own.
        $kind = $this->kind($type);
        abort_unless($submission->type === $kind
            && $attachment->employee_service_feedback_id === $submission->getKey(), 404);
        $this->assertAccess($this->user(), $submission);
        $this->authorize('view', $submission);
        abort_unless($this->visibility->canSeeAttachments($this->user()), 403);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($attachment->path), 404);
        // Streamed through the disk so it also works on shared object storage.
        if (! StoredFileChecksum::matches('local', $attachment->path, $attachment->sha256)) {
            report(new \RuntimeException('Public submission evidence integrity mismatch: '.$attachment->getKey()));
            abort(409);
        }

        $this->audit->execute(
            eventType: $kind === ServiceFeedbackType::Complaint ? AuditEventType::ServiceComplaintAttachmentDownloaded : AuditEventType::ServiceTipOffAttachmentDownloaded,
            actor: $this->user(),
            auditable: $attachment,
            organizationId: $submission->organization_id,
            reason: $kind === ServiceFeedbackType::Complaint ? 'Complaint attachment downloaded' : 'Tip-off evidence downloaded',
            request: $request,
        );

        return $disk->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function kind(string $type): ServiceFeedbackType
    {
        abort_unless(in_array($type, ['suggestion', 'complaint'], true), 404);

        return ServiceFeedbackType::from($type);
    }

    private function viewPermission(ServiceFeedbackType $type): string
    {
        return $type === ServiceFeedbackType::Complaint
            ? 'service_feedback.complaints.view' : 'service_feedback.view';
    }

    /** View permission, organization scope, and never the submission's own target employee. */
    private function assertAccess(User $user, EmployeeServiceFeedback $entry): void
    {
        abort_unless($user->hasAnyPermission([$this->viewPermission($entry->type)])
            && $this->visibility->canSeeTarget($user, $entry->target_type)
            && ($user->employee_id === null || $entry->employee_id === null || (string) $user->employee_id !== (string) $entry->employee_id)
            && ($this->scope->isUnrestricted($user)
                || ($entry->organization_id !== null && $this->scope->canAccessOrganization($user, $entry->organization_id))), 403);
    }

    /** @return Builder<EmployeeServiceFeedback> */
    private function scoped(User $user, ServiceFeedbackType $type): Builder
    {
        $query = $this->visibility->applyTargetScope(EmployeeServiceFeedback::query()->where('type', $type->value), $user);
        if ($user->employee_id !== null) {
            $query->where(fn (Builder $q) => $q->whereNull('employee_id')->orWhere('employee_id', '!=', $user->employee_id));
        }
        if (! $this->scope->isUnrestricted($user)) {
            $query->whereIn('organization_id', $this->scope->accessibleOrganizationIds($user)->all());
        }

        return $query;
    }

    /** Open assignments addressed to this user, one of their roles, or their current unit. */
    private function handledBy(Builder $query, User $user): Builder
    {
        $roleIds = $user->roles->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $unitId = $user->employee?->currentAssignment?->organization_unit_id;

        return $query->where(function (Builder $q) use ($user, $roleIds, $unitId): void {
            $q->where(fn (Builder $q) => $q->where('handler_type', PublicFeedbackHandlerType::User->value)->where('handler_id', (string) $user->getKey()));
            if ($roleIds !== []) {
                $q->orWhere(fn (Builder $q) => $q->where('handler_type', PublicFeedbackHandlerType::Role->value)->whereIn('handler_id', $roleIds));
            }
            if ($unitId !== null) {
                $q->orWhere(fn (Builder $q) => $q->where('handler_type', PublicFeedbackHandlerType::OrganizationUnit->value)->where('handler_id', (string) $unitId));
            }
        });
    }

    /** @return array<string, mixed> */
    private function summary(EmployeeServiceFeedback $entry): array
    {
        $locale = app()->getLocale();

        return [
            'id' => $entry->id,
            'reference' => $entry->reference_number,
            'type' => $entry->type->value,
            'tip_type' => $entry->tip_type?->value,
            'status' => $entry->status->value,
            'target_type' => $entry->target_type?->value ?? 'employee',
            // An institution in the reader's language (the snapshot only when the institution is gone); an employee by the recorded label.
            'target_name' => $entry->target_type === PublicFeedbackTargetType::Organization && $entry->organizationTarget !== null
                ? ($locale === 'am' && filled($entry->organizationTarget->name_am) ? $entry->organizationTarget->name_am : $entry->organizationTarget->name_en)
                : ($entry->target_name_snapshot ?? $entry->employee?->full_name),
            'target_code' => $entry->target_type === PublicFeedbackTargetType::Organization ? $entry->organizationTarget?->code : null,
            'message' => $entry->comment,
            'created_at' => $entry->created_at?->toIso8601String(),
            'submitted_at' => ($entry->submitted_at ?? $entry->created_at)?->toIso8601String(),
            'employee' => $entry->employee?->full_name,
            'employee_number' => $entry->employee?->employee_number,
            'organization' => $locale === 'am' ? ($entry->organization?->name_am ?? $entry->organization?->name_en) : $entry->organization?->name_en,
            'handler' => $entry->currentAssignment?->handlerName($locale),
        ];
    }

    /** @return array<string, mixed> */
    private function assignmentRow(PublicFeedbackAssignment $row, string $locale): array
    {
        return [
            'id' => $row->id,
            'handler_type' => $row->handler_type->value,
            'handler' => $row->handlerName($locale),
            'assigned_by' => $row->assignedBy?->name,
            'via_rule' => $row->routing_rule_id !== null,
            'reason' => $row->reason,
            'assigned_at' => $row->assigned_at?->toIso8601String(),
            'ended_at' => $row->ended_at?->toIso8601String(),
        ];
    }
}
