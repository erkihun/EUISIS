<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a legal source stands. A source is evidence, not a draft: it is
 * active until the instrument is replaced (superseded) or withdrawn
 * (repealed). Either way the row and its citations stay for history.
 */
enum LegalSourceStatus: string
{
    case Active = 'active';
    case Superseded = 'superseded';
    case Repealed = 'repealed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
