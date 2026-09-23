<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Reviewer decisions. A reviewer may never edit the proposal and approve
 * different values; changing the proposal requires RequestCorrection, which
 * hands the request back to the requester.
 */
enum OrganizationalChangeReviewAction: string
{
    case StartReview = 'start_review';
    case RequestCorrection = 'request_correction';
    case Approve = 'approve';
    case Reject = 'reject';

    /** Actions that cannot proceed without a reviewer comment. */
    public function requiresComment(): bool
    {
        return in_array($this, [self::RequestCorrection, self::Reject], true);
    }
}
