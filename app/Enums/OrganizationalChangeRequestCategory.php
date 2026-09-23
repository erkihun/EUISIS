<?php

declare(strict_types=1);

namespace App\Enums;

/** Broad grouping of request types, used for filtering and routing. */
enum OrganizationalChangeRequestCategory: string
{
    case OrganizationUnit = 'organization_unit';
    case Position = 'position';
    case Other = 'other';
}
