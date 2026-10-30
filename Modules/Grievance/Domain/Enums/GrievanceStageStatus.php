<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Status of one handler stage of a case.
 */
enum GrievanceStageStatus: string
{
    case Pending = 'pending';
    case Received = 'received';
    case UnderReview = 'under_review';
    case AwaitingInformation = 'awaiting_information';
    case HearingScheduled = 'hearing_scheduled';
    case DecisionDrafting = 'decision_drafting';
    case PendingApproval = 'pending_approval';
    case ReturnedForCorrection = 'returned_for_correction';
    case Resolved = 'resolved';
    case Escalated = 'escalated';
    case Appealed = 'appealed';
    case Referred = 'referred';
    case Reassigned = 'reassigned';
    case Closed = 'closed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
