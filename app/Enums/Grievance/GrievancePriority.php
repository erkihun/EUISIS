<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Case priority.
 */
enum GrievancePriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
