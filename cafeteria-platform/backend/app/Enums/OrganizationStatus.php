<?php

declare(strict_types=1);

namespace App\Enums;

enum OrganizationStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Inactive = 'inactive';
    case Merged = 'merged';
    case Dissolved = 'dissolved';
    case Archived = 'archived';
}
