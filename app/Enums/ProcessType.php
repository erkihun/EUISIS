<?php

declare(strict_types=1);

namespace App\Enums;

/** How a process serves the institution: delivering value, supporting it, or steering it. */
enum ProcessType: string
{
    case Core = 'core';
    case Support = 'support';
    case Management = 'management';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
