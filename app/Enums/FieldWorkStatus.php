<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a field work request (docs/field-work-management.md §Workflow).
 *
 *   draft ─submit→ pending_supervisor_approval ─approve→ approved ─first check-in→ in_field ─complete→ completed
 *                     │            │                       │
 *                     │            ├─return→ returned_for_correction ─submit→ pending…
 *                     │            └─reject→ rejected
 *                     └─cancel (requester, before a decision) / cancel approved (before any check-in)
 *
 * "Submitted" is not a separate resting state: submitting puts the request
 * straight in front of the resolved supervisor. APPROVED and COMPLETED stay
 * distinct: approval authorises the work, completion records that it ended.
 * Overdue / check-in missing are derived monitoring flags, never stored.
 */
enum FieldWorkStatus: string
{
    case Draft = 'draft';
    case PendingSupervisorApproval = 'pending_supervisor_approval';
    case ReturnedForCorrection = 'returned_for_correction';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case InField = 'in_field';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** @return array<int, self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft, self::ReturnedForCorrection => [self::PendingSupervisorApproval, self::Cancelled],
            self::PendingSupervisorApproval => [self::Approved, self::ReturnedForCorrection, self::Rejected, self::Cancelled],
            self::Approved => [self::InField, self::Cancelled],
            self::InField => [self::Completed],
            self::Completed, self::Rejected, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** The requester may still change the request content. */
    public function isRequesterEditable(): bool
    {
        return in_array($this, [self::Draft, self::ReturnedForCorrection], true);
    }

    /** Authorised official work: absence from the office is explained. */
    public function isAuthorised(): bool
    {
        return in_array($this, [self::Approved, self::InField, self::Completed], true);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Approved, self::InField], true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /** @return array<int, string> */
    public static function authorisedValues(): array
    {
        return [self::Approved->value, self::InField->value, self::Completed->value];
    }

    /** @return array<int, string> */
    public static function openValues(): array
    {
        return [self::Approved->value, self::InField->value];
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
