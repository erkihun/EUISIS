<?php

declare(strict_types=1);

namespace Modules\Grievance\Domain\Enums;

enum GrievanceOriginLevel: string
{
    case Woreda = 'woreda';
    case Pool = 'pool';
    case Organization = 'organization';
    case OrganizationUnit = 'organization_unit';
}
