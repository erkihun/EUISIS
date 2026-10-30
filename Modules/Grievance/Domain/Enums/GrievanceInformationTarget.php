<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Who an information request is addressed to.
 */
enum GrievanceInformationTarget: string
{
    case Employee = 'employee';
    case Hr = 'hr';
    case OrganizationUnit = 'organization_unit';
    case Organization = 'organization';
    case External = 'external';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
