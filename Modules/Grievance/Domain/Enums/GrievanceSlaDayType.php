<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * How an SLA day count is measured.
 */
enum GrievanceSlaDayType: string
{
    case WorkingDays = 'working_days';
    case CalendarDays = 'calendar_days';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
