<?php

declare(strict_types=1);

namespace App\Enums;

enum OrganizationTypeCategory: string
{
    case Root = 'root';
    case Functional = 'functional';
    case Geographic = 'geographic';
    case ServiceProvider = 'service_provider';
    case Independent = 'independent';
    case Other = 'other';
}
