<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Actions recorded in a decision's approval history.
 */
enum GrievanceApprovalAction: string
{
    case SubmitForReview = 'submit_for_review';
    case Endorse = 'endorse';
    case SubmitForApproval = 'submit_for_approval';
    case Approve = 'approve';
    case ReturnForCorrection = 'return_for_correction';
    case Reject = 'reject';
    case Finalize = 'finalize';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
