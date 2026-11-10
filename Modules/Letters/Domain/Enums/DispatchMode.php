<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Enums;

/** How a dispatched letter left the institution; the dispatch register records it with the tracking reference. */
enum DispatchMode: string
{
    case System = 'system';
    case Hand = 'hand';
    case Post = 'post';
    case Courier = 'courier';
    case Email = 'email';
    case Fax = 'fax';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
