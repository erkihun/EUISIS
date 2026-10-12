<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Workflow state of a public submission. One vocabulary serves three
 * type-specific workflows (brief §48–51): rating feedback is lightweight
 * (pending → reviewed/resolved → closed), a suggestion ends in an outcome
 * (accepted or not adopted) and a complaint passes through assignment and
 * review before it is resolved and closed. PublicFeedbackWorkflow holds the
 * transitions per type.
 *
 * `assigned` and `unrouted` are set by routing or by an assignment, never
 * chosen by hand; `hidden` is moderation, not workflow — it removes a comment
 * from reports and employee-facing views without deleting it.
 */
enum ServiceFeedbackStatus: string
{
    case Pending = 'pending';
    case Unrouted = 'unrouted';
    case Assigned = 'assigned';
    case Reviewed = 'reviewed';
    case UnderReview = 'under_review';
    case Accepted = 'accepted';
    case NotAdopted = 'not_adopted';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Hidden = 'hidden';

    public function isVisibleToEmployee(): bool
    {
        return $this !== self::Hidden;
    }

    /** States no one picks from a status list: routing, assignment and moderation set them. */
    public function isSystemManaged(): bool
    {
        return in_array($this, [self::Pending, self::Unrouted, self::Assigned, self::Hidden], true);
    }

    /** An outcome was recorded: the submission waits only for closure. */
    public function isOutcome(): bool
    {
        return in_array($this, [self::Resolved, self::Accepted, self::NotAdopted], true);
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Closed, self::Hidden], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
