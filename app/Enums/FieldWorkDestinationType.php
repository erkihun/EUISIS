<?php

declare(strict_types=1);

namespace App\Enums;

enum FieldWorkDestinationType: string
{
    case RegisteredOrganization = 'registered_organization';
    case ExternalOrganization = 'external_organization';
    case FieldSite = 'field_site';
    case OtherLocation = 'other_location';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
