<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Role of an officer explicitly assigned to a case at an organization-unit stage.
 */
enum GrievanceCaseOfficerRole: string
{
    case Lead = 'lead';
    case Officer = 'officer';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
