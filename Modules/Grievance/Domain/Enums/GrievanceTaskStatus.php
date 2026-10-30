<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Case task lifecycle.
 */
enum GrievanceTaskStatus: string
{
    case Open = 'open';
    case Done = 'done';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
