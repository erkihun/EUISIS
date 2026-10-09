<?php

declare(strict_types=1);

namespace App\Enums;

enum OrganizationUnitStatus: string
{
    case Draft    = 'draft';
    case Active   = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';
}
