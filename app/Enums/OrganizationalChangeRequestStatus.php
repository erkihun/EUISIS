<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of an organizational change request.
 *
 * The central business rule lives here: APPROVED is not IMPLEMENTED. Approval
 * authorises the responsible implementing unit to apply the approved change;
 * it never turns the requester into an editor of master data. That is why
 * Approved transitions only to PendingImplementation, and why the
 * implementation statuses are reachable only from the implementation side.
 */
enum OrganizationalChangeRequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case CorrectionRequested = 'correction_requested';
    case Resubmitted = 'resubmitted';
    case Approved = 'approved';
    case PendingImplementation = 'pending_implementation';
    case Implementing = 'implementing';
    case ImplementationBlocked = 'implementation_blocked';
    case Implemented = 'implemented';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * Allowed forward transitions. Anything not listed is refused by
     * {@see self::canTransitionTo()}, which every action calls before writing.
     *
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted, self::Cancelled],
            self::Submitted => [self::UnderReview, self::CorrectionRequested, self::Approved, self::Rejected, self::Cancelled],
            self::UnderReview => [self::CorrectionRequested, self::Approved, self::Rejected, self::Cancelled],
            self::CorrectionRequested => [self::Resubmitted, self::Cancelled],
            self::Resubmitted => [self::UnderReview, self::CorrectionRequested, self::Approved, self::Rejected, self::Cancelled],
            // Approval never reaches Implemented directly. The implementing
            // unit must claim the request first.
            self::Approved => [self::PendingImplementation],
            self::PendingImplementation => [self::Implementing, self::ImplementationBlocked, self::Cancelled],
            self::Implementing => [self::Implemented, self::ImplementationBlocked],
            self::ImplementationBlocked => [self::PendingImplementation, self::CorrectionRequested, self::Cancelled],
            self::Implemented => [self::Completed],
            self::Completed, self::Rejected, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** Terminal states never move again. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Rejected, self::Cancelled], true);
    }

    /** The requester may still edit the proposed payload. */
    public function isRequesterEditable(): bool
    {
        return in_array($this, [self::Draft, self::CorrectionRequested], true);
    }

    /** Sitting with a reviewer, awaiting a decision. */
    public function isAwaitingReview(): bool
    {
        return in_array($this, [self::Submitted, self::UnderReview, self::Resubmitted], true);
    }

    /**
     * Approved and handed to the implementing unit, but not yet applied.
     * Only these states may enter {@see ApplyApprovedOrganizationalChangeService}.
     */
    public function isAwaitingImplementation(): bool
    {
        return in_array($this, [self::Approved, self::PendingImplementation], true);
    }

    /** The approved payload is frozen and must never be edited again. */
    public function isPayloadLocked(): bool
    {
        return ! $this->isRequesterEditable() && $this !== self::Submitted && $this !== self::Resubmitted;
    }

    /** The requester may withdraw the request. */
    public function isCancellableByRequester(): bool
    {
        return in_array($this, [self::Draft, self::Submitted, self::UnderReview, self::CorrectionRequested, self::Resubmitted], true);
    }

    /** Master data has been changed as a result of this request. */
    public function hasBeenApplied(): bool
    {
        return in_array($this, [self::Implemented, self::Completed], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
