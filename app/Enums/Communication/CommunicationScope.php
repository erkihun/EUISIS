<?php

declare(strict_types=1);

namespace App\Enums\Communication;

/** Whom an employee can find in the communication directory. */
enum CommunicationScope: string
{
    case OwnOrganization = 'OWN_ORGANIZATION';
    case OwnSubtree = 'OWN_SUBTREE';
    case AssignedOrganizationScopes = 'ASSIGNED_ORGANIZATION_SCOPES';
    case CityWide = 'CITY_WIDE';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
