<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Enums;

/** Which way a letter travels, as seen by the institution whose registry holds it. */
enum LetterDirection: string
{
    case Incoming = 'incoming';
    case Outgoing = 'outgoing';
    case Internal = 'internal';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
