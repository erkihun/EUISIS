<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Kinds of case task.
 */
enum GrievanceTaskType: string
{
    case CollectResponse = 'collect_response';
    case ScheduleHearing = 'schedule_hearing';
    case PrepareDecision = 'prepare_decision';
    case PrepareLetter = 'prepare_letter';
    case ObtainApproval = 'obtain_approval';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
