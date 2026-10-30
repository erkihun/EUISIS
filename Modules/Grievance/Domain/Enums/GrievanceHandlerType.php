<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Who handles a case stage. `organization` is only a routing entry point (the
 * complainant's organization), never a stage handler.
 */
enum GrievanceHandlerType: string
{
    case Committee = 'committee';
    case OrganizationUnit = 'organization_unit';
    case ExternalAuthority = 'external_authority';
    case Organization = 'organization';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
