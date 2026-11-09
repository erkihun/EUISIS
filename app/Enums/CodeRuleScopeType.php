<?php

declare(strict_types=1);

namespace App\Enums;

enum CodeRuleScopeType: string
{
    case Organization = 'organization';
    case OrganizationType = 'organization_type';
    case OrganizationUnitType = 'organization_unit_type';
    case ServiceType = 'service_type';
}
