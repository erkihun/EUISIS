<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where the field work takes place.
 *
 * REGISTERED_ORGANIZATION points at existing Organization master data; an
 * EXTERNAL_ORGANIZATION is stored as text on the request and is never
 * registered as master data automatically.
 */
enum FieldWorkDestinationType: string
{
    case RegisteredOrganization = 'registered_organization';
    case ExternalOrganization = 'external_organization';
    case FieldSite = 'field_site';
    case OtherLocation = 'other_location';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
