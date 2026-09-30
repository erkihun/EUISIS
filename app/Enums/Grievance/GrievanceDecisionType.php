<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Outcome of a grievance decision.
 */
enum GrievanceDecisionType: string
{
    case Upheld = 'upheld';
    case PartiallyUpheld = 'partially_upheld';
    case NotUpheld = 'not_upheld';
    case Settled = 'settled';
    case Referred = 'referred';
    case Inadmissible = 'inadmissible';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
