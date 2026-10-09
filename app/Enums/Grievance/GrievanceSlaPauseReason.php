<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Approved reasons for pausing a stage deadline.
 */
enum GrievanceSlaPauseReason: string
{
    case AwaitingEmployeeInformation = 'awaiting_employee_information';
    case AwaitingExternalInformation = 'awaiting_external_information';
    case LegalHold = 'legal_hold';
    case SystemApprovedPause = 'system_approved_pause';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
