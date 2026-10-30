<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Institutional corrective action lifecycle.
 */
enum GrievanceCorrectiveActionStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
