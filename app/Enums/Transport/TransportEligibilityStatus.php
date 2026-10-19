<?php

declare(strict_types=1);

namespace App\Enums\Transport;

/**
 *   active ─suspend→ suspended ─reinstate→ active
 *   active | suspended ─end→ ended
 */
enum TransportEligibilityStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Ended = 'ended';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
