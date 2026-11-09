<?php

declare(strict_types=1);

namespace Modules\Grievance\Domain\Enums;

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
