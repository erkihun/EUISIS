<?php

declare(strict_types=1);

namespace Modules\Grievance\Domain\Enums;

/**
 * Hearing lifecycle.
 */
enum GrievanceHearingStatus: string
{
    case Scheduled = 'scheduled';
    case Held = 'held';
    case Adjourned = 'adjourned';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
