<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * How a hearing is held.
 */
enum GrievanceHearingMode: string
{
    case InPerson = 'in_person';
    case Virtual = 'virtual';
    case Hybrid = 'hybrid';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
