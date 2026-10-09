<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Role of a member on a grievance committee. Committee roles are NOT system roles.
 */
enum GrievanceCommitteeRole: string
{
    case Chairperson = 'chairperson';
    case Writer = 'writer';
    case Member = 'member';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
