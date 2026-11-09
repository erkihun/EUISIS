<?php

declare(strict_types=1);

namespace App\Enums;

enum OrganizationScopeType: string
{
    case Self = 'self';
    case Subtree = 'subtree';
    case ServiceProvider = 'service_provider';
    case Citywide = 'citywide';
}
