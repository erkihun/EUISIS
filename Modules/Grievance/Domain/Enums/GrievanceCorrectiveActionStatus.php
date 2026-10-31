<?php

declare(strict_types=1);

namespace Modules\Grievance\Domain\Enums;

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
