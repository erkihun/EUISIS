<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Decision lifecycle. Returned and rejected versions are kept; a correction is a
 * new version.
 */
enum GrievanceDecisionStatus: string
{
    case Draft = 'draft';
    case UnderInternalReview = 'under_internal_review';
    case PendingExecutiveApproval = 'pending_executive_approval';
    case ReturnedForCorrection = 'returned_for_correction';
    case Resubmitted = 'resubmitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Finalized = 'finalized';
    case Issued = 'issued';
    case Superseded = 'superseded';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
