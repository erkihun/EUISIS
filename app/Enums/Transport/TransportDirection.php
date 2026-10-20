<?php

declare(strict_types=1);

namespace App\Enums\Transport;

/**
 * Which way a trip runs. Direction is configured on the schedule and copied
 * to the trip and every boarding; it is never inferred from the clock.
 */
enum TransportDirection: string
{
    /** Home stops → work organization (the morning service). */
    case Inbound = 'inbound';

    /** Work organization → home stops (the evening service). */
    case Outbound = 'outbound';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
