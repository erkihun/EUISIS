<?php

declare(strict_types=1);

namespace App\Enums;

enum RelationshipTargetType: string
{
    case Organization = 'organization';
    case InstitutionOffice = 'institution_office';
    case OrganizationUnit = 'organization_unit';

    public function label(): string
    {
        return match ($this) {
            self::Organization => __('relationships.target_types.organization'),
            self::InstitutionOffice => __('relationships.target_types.institution_office'),
            self::OrganizationUnit => __('relationships.target_types.organization_unit'),
        };
    }
}
