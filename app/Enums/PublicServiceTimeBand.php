<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Roughly when the visitor was served. A band, not a clock time: an exact
 * time invites confusion between the Ethiopian and the international clock
 * and is more than the feedback needs.
 */
enum PublicServiceTimeBand: string
{
    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Evening = 'evening';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
