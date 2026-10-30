<?php

declare(strict_types=1);

namespace Modules\Entitlements\Domain\Enums;

/** How a service is delivered. */
enum ServiceDeliveryChannel: string
{
    case InPerson = 'in_person';
    case Online = 'online';
    case Phone = 'phone';
    case Field = 'field';
    case Mixed = 'mixed';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
