<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * What a letter recipient is.
 */
enum GrievanceRecipientType: string
{
    case Employee = 'employee';
    case Organization = 'organization';
    case OrganizationUnit = 'organization_unit';
    case Committee = 'committee';
    case External = 'external';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
