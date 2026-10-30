<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Official grievance correspondence types.
 */
enum GrievanceLetterType: string
{
    case Acknowledgment = 'acknowledgment';
    case HearingNotice = 'hearing_notice';
    case InformationRequest = 'information_request';
    case DecisionLetter = 'decision_letter';
    case AppealAcknowledgment = 'appeal_acknowledgment';
    case EscalationNotice = 'escalation_notice';
    case ClosureLetter = 'closure_letter';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
