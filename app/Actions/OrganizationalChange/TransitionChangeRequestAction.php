<?php

declare(strict_types=1);

namespace App\Actions\OrganizationalChange;

use App\Enums\AuditEventType;
use App\Enums\OrganizationalChangeRequestStatus;
use App\Enums\OrganizationalChangeReviewAction;
use App\Models\OrganizationalChangeRequest;
use App\Models\OrganizationalChangeRequestReview;
use App\Models\User;
use App\Services\OrganizationalChange\ChangeRequestHistoryRecorder;
use App\Services\OrganizationalChange\ChangeRequestImpactAnalyzer;
use App\Services\OrganizationalChange\ChangeRequestNotifier;
use App\Services\OrganizationalChange\ImplementingUnitResolver;
use App\Services\OrganizationStructure\ApplyApprovedOrganizationalChangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every workflow move that is not the implementation itself.
 *
 * One class holds the lifecycle so the legal transitions live in one place and
 * each is checked against the status enum before anything is written.
 *
 * The approval path is the important one: it freezes the payload, fingerprints
 * it, routes the request to an implementing unit and moves the status to
 * PENDING_IMPLEMENTATION. It grants the requester nothing. No permission,
 * role or scope record is touched anywhere in this class.
 */
final readonly class TransitionChangeRequestAction
{
    public function __construct(
        private ChangeRequestHistoryRecorder $history,
        private ChangeRequestImpactAnalyzer $impact,
        private ImplementingUnitResolver $implementingUnits,
        private ChangeRequestNotifier $notifier,
    ) {}

    // ── Requester side ──────────────────────────────────────────────────────

    public function submit(OrganizationalChangeRequest $request, User $actor, ?Request $httpRequest = null): OrganizationalChangeRequest
    {
        $this->assertTransition($request, OrganizationalChangeRequestStatus::Submitted);

        return DB::transaction(function () use ($request, $actor, $httpRequest): OrganizationalChangeRequest {
            $from = $request->status;

            $request->forceFill([
                'status' => OrganizationalChangeRequestStatus::Submitted->value,
                'submitted_at' => now(),
            ])->save();

            $this->history->record(
                request: $request,
                actor: $actor,
                action: 'submitted',
                from: $from,
                to: OrganizationalChangeRequestStatus::Submitted,
                auditEvent: AuditEventType::OrganizationalChangeRequestSubmitted,
                httpRequest: $httpRequest,
            );

            $this->notifier->submitted($request);

            return $request;
        });
    }

    /** Requester answers a correction and sends the request back to review. */
    public function resubmit(
        OrganizationalChangeRequest $request,
        User $actor,
        ?string $comment = null,
        ?Request $httpRequest = null,
    ): OrganizationalChangeRequest {
        $this->assertTransition($request, OrganizationalChangeRequestStatus::Resubmitted);

        return DB::transaction(function () use ($request, $actor, $comment, $httpRequest): OrganizationalChangeRequest {
            $from = $request->status;

            $request->forceFill([
                'status' => OrganizationalChangeRequestStatus::Resubmitted->value,
                'submitted_at' => now(),
                // A new revision so the review trail keeps each round apart.
                'revision' => $request->revision + 1,
            ])->save();

            $this->history->record(
                request: $request,
                actor: $actor,
                action: 'resubmitted',
                from: $from,
                to: OrganizationalChangeRequestStatus::Resubmitted,
                comment: $comment,
                auditEvent: AuditEventType::OrganizationalChangeRequestResubmitted,
                httpRequest: $httpRequest,
            );

            $this->notifier->resubmitted($request);

            return $request;
        });
    }

    public function cancel(
        OrganizationalChangeRequest $request,
        User $actor,
        ?string $reason = null,
        ?Request $httpRequest = null,
    ): OrganizationalChangeRequest {
        $this->assertTransition($request, OrganizationalChangeRequestStatus::Cancelled);

        return DB::transaction(function () use ($request, $actor, $reason, $httpRequest): OrganizationalChangeRequest {
            $from = $request->status;

            $request->forceFill([
                'status' => OrganizationalChangeRequestStatus::Cancelled->value,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->getKey(),
            ])->save();

            $this->history->record(
                request: $request,
                actor: $actor,
                action: 'cancelled',
                from: $from,
                to: OrganizationalChangeRequestStatus::Cancelled,
                comment: $reason,
                auditEvent: AuditEventType::OrganizationalChangeRequestCancelled,
                httpRequest: $httpRequest,
            );

            return $request;
        });
    }

    // ── Reviewer side ───────────────────────────────────────────────────────

    public function startReview(OrganizationalChangeRequest $request, User $reviewer, ?Request $httpRequest = null): OrganizationalChangeRequest
    {
        $this->assertTransition($request, OrganizationalChangeRequestStatus::UnderReview);

        return DB::transaction(function () use ($request, $reviewer, $httpRequest): OrganizationalChangeRequest {
            $from = $request->status;

            $request->forceFill([
                'status' => OrganizationalChangeRequestStatus::UnderReview->value,
                'reviewed_by' => $reviewer->getKey(),
                'review_started_at' => now(),
            ])->save();

            $this->recordReview($request, $reviewer, OrganizationalChangeReviewAction::StartReview, null);

            $this->history->record(
                request: $request,
                actor: $reviewer,
                action: 'review_started',
                from: $from,
                to: OrganizationalChangeRequestStatus::UnderReview,
                auditEvent: AuditEventType::OrganizationalChangeRequestReviewStarted,
                httpRequest: $httpRequest,
            );

            return $request;
        });
    }

    /**
     * Hand the request back for correction.
     *
     * This is the only way a reviewer can change what is being asked for: they
     * cannot edit the proposal and approve different values, so anything that
     * needs changing goes back to the requester with a required comment.
     */
    public function requestCorrection(
        OrganizationalChangeRequest $request,
        User $reviewer,
        string $comment,
        ?Request $httpRequest = null,
    ): OrganizationalChangeRequest {
        if (trim($comment) === '') {
            throw ValidationException::withMessages([
                'comment' => __('organizational-change-requests.errors.correction_comment_required'),
            ]);
        }

        $this->assertTransition($request, OrganizationalChangeRequestStatus::CorrectionRequested);

        return DB::transaction(function () use ($request, $reviewer, $comment, $httpRequest): OrganizationalChangeRequest {
            $from = $request->status;

            $request->forceFill([
                'status' => OrganizationalChangeRequestStatus::CorrectionRequested->value,
                'reviewed_by' => $reviewer->getKey(),
            ])->save();

            $this->recordReview($request, $reviewer, OrganizationalChangeReviewAction::RequestCorrection, $comment);

            $this->history->record(
                request: $request,
                actor: $reviewer,
                action: 'correction_requested',
                from: $from,
                to: OrganizationalChangeRequestStatus::CorrectionRequested,
                comment: $comment,
                auditEvent: AuditEventType::OrganizationalChangeRequestCorrectionRequested,
                httpRequest: $httpRequest,
            );

            $this->notifier->correctionRequested($request, $comment);

            return $request;
        });
    }

    /**
     * Approve, freeze and route.
     *
     * Note what does NOT happen here: no role is assigned, no permission is
     * granted, nothing is written to user_organization_scopes. Approval only
     * authorises the implementing unit to apply the frozen payload.
     */
    public function approve(
        OrganizationalChangeRequest $request,
        User $approver,
        ?string $comment = null,
        ?Request $httpRequest = null,
    ): OrganizationalChangeRequest {
        $this->assertTransition($request, OrganizationalChangeRequestStatus::Approved);

        // Separation of duties: approving your own request needs a distinct
        // permission, which ordinary reviewers do not hold.
        if ((int) $request->requested_by === (int) $approver->getKey()
            && ! $approver->can('organizational-change-requests.approve_own')) {
            abort(403, __('organizational-change-requests.errors.cannot_approve_own'));
        }

        return DB::transaction(function () use ($request, $approver, $comment, $httpRequest): OrganizationalChangeRequest {
            $from = $request->status;
            $request->load('items');

            $routing = $this->implementingUnits->resolve($request);

            $request->forceFill([
                'status' => OrganizationalChangeRequestStatus::Approved->value,
                'approved_by' => $approver->getKey(),
                'approved_at' => now(),
                'decision_comment' => $comment,
                // Freeze exactly what was approved, and fingerprint it so a
                // later edit cannot be applied silently.
                'approved_payload' => [
                    'request_type' => $request->request_type->value,
                    'organization_id' => $request->organization_id,
                    'requested_effective_date' => $request->requested_effective_date?->toDateString(),
                    'items' => $request->items->map(static fn ($item): array => [
                        'entity_type' => $item->entity_type->value,
                        'entity_id' => $item->entity_id,
                        'action' => $item->action->value,
                        'before_data' => $item->before_data,
                        'proposed_data' => $item->proposed_data,
                    ])->all(),
                    'impact_at_approval' => $this->impact->analyze($request),
                ],
                'approved_payload_hash' => ApplyApprovedOrganizationalChangeService::fingerprint($request),
                'implementing_unit_key' => $routing['key'],
                'implementing_unit_id' => $routing['unit_id'],
            ])->save();

            $this->recordReview($request, $approver, OrganizationalChangeReviewAction::Approve, $comment);

            $this->history->record(
                request: $request,
                actor: $approver,
                action: 'approved',
                from: $from,
                to: OrganizationalChangeRequestStatus::Approved,
                comment: $comment,
                context: ['implementing_unit_key' => $routing['key'], 'implementing_unit_id' => $routing['unit_id']],
                auditEvent: AuditEventType::OrganizationalChangeRequestApproved,
                httpRequest: $httpRequest,
            );

            // APPROVED is never the resting state: the request immediately
            // becomes the implementing unit's work item.
            $request->forceFill([
                'status' => OrganizationalChangeRequestStatus::PendingImplementation->value,
            ])->save();

            $this->history->record(
                request: $request,
                actor: $approver,
                action: 'pending_implementation',
                from: OrganizationalChangeRequestStatus::Approved,
                to: OrganizationalChangeRequestStatus::PendingImplementation,
                context: ['implementing_unit_key' => $routing['key']],
                httpRequest: $httpRequest,
            );

            $this->notifier->approved($request);

            return $request;
        });
    }

    public function reject(
        OrganizationalChangeRequest $request,
        User $reviewer,
        string $comment,
        ?Request $httpRequest = null,
    ): OrganizationalChangeRequest {
        if (trim($comment) === '') {
            throw ValidationException::withMessages([
                'comment' => __('organizational-change-requests.errors.rejection_comment_required'),
            ]);
        }

        $this->assertTransition($request, OrganizationalChangeRequestStatus::Rejected);

        return DB::transaction(function () use ($request, $reviewer, $comment, $httpRequest): OrganizationalChangeRequest {
            $from = $request->status;

            $request->forceFill([
                'status' => OrganizationalChangeRequestStatus::Rejected->value,
                'rejected_by' => $reviewer->getKey(),
                'rejected_at' => now(),
                'decision_comment' => $comment,
            ])->save();

            $this->recordReview($request, $reviewer, OrganizationalChangeReviewAction::Reject, $comment);

            $this->history->record(
                request: $request,
                actor: $reviewer,
                action: 'rejected',
                from: $from,
                to: OrganizationalChangeRequestStatus::Rejected,
                comment: $comment,
                auditEvent: AuditEventType::OrganizationalChangeRequestRejected,
                httpRequest: $httpRequest,
            );

            $this->notifier->rejected($request, $comment);

            return $request;
        });
    }

    // ── Implementation side (assignment and amendment only) ─────────────────

    public function assignImplementation(
        OrganizationalChangeRequest $request,
        User $actor,
        ?int $assigneeId,
        ?string $implementingUnitId = null,
        ?Request $httpRequest = null,
    ): OrganizationalChangeRequest {
        if (! $request->status->isAwaitingImplementation()
            && $request->status !== OrganizationalChangeRequestStatus::ImplementationBlocked) {
            throw ValidationException::withMessages([
                'status' => __('organizational-change-requests.errors.not_assignable'),
            ]);
        }

        $request->forceFill(array_filter([
            'implementation_assigned_to' => $assigneeId,
            'implementation_assigned_by' => $actor->getKey(),
            'implementation_assigned_at' => now(),
            'implementing_unit_id' => $implementingUnitId ?? $request->implementing_unit_id,
        ], static fn ($value): bool => $value !== null))->save();

        $this->history->record(
            request: $request,
            actor: $actor,
            action: 'implementation_assigned',
            from: $request->status,
            to: $request->status,
            context: ['assigned_to' => $assigneeId, 'implementing_unit_id' => $request->implementing_unit_id],
            auditEvent: AuditEventType::OrganizationalChangeRequestImplementationAssigned,
            httpRequest: $httpRequest,
        );

        return $request;
    }

    /**
     * The implementing unit cannot proceed and sends the request back rather
     * than applying something other than what was approved.
     */
    public function returnForAmendment(
        OrganizationalChangeRequest $request,
        User $actor,
        string $comment,
        ?Request $httpRequest = null,
    ): OrganizationalChangeRequest {
        if (trim($comment) === '') {
            throw ValidationException::withMessages([
                'comment' => __('organizational-change-requests.errors.amendment_comment_required'),
            ]);
        }

        if (! in_array($request->status, [
            OrganizationalChangeRequestStatus::PendingImplementation,
            OrganizationalChangeRequestStatus::ImplementationBlocked,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => __('organizational-change-requests.errors.not_returnable'),
            ]);
        }

        return DB::transaction(function () use ($request, $actor, $comment, $httpRequest): OrganizationalChangeRequest {
            $from = $request->status;

            $request->forceFill([
                'status' => OrganizationalChangeRequestStatus::CorrectionRequested->value,
                // The frozen payload is released so the requester can revise it;
                // a fresh approval will fingerprint the new proposal.
                'approved_payload_hash' => null,
                'implementation_started_at' => null,
                'implementation_claimed_by' => null,
            ])->save();

            $this->history->record(
                request: $request,
                actor: $actor,
                action: 'returned_for_amendment',
                from: $from,
                to: OrganizationalChangeRequestStatus::CorrectionRequested,
                comment: $comment,
                auditEvent: AuditEventType::OrganizationalChangeRequestReturnedForAmendment,
                httpRequest: $httpRequest,
            );

            $this->notifier->correctionRequested($request, $comment);

            return $request;
        });
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @throws ValidationException */
    private function assertTransition(OrganizationalChangeRequest $request, OrganizationalChangeRequestStatus $to): void
    {
        if (! $request->status->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'status' => __('organizational-change-requests.errors.invalid_transition', [
                    'from' => __('organizational-change-requests.statuses.'.$request->status->value),
                    'to' => __('organizational-change-requests.statuses.'.$to->value),
                ]),
            ]);
        }
    }

    private function recordReview(
        OrganizationalChangeRequest $request,
        User $reviewer,
        OrganizationalChangeReviewAction $action,
        ?string $comment,
    ): OrganizationalChangeRequestReview {
        return OrganizationalChangeRequestReview::query()->create([
            'request_id' => $request->getKey(),
            'reviewer_id' => $reviewer->getKey(),
            'stage' => 'organization_review',
            'action' => $action->value,
            'comment' => $comment,
            'revision' => $request->revision,
            'reviewed_at' => now(),
        ]);
    }
}
