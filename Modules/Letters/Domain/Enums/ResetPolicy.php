<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Enums;

/** When a numbering sequence starts again at one. */
enum ResetPolicy: string
{
    case EthiopianYear = 'ethiopian_year';
    case GregorianYear = 'gregorian_year';
    case Never = 'never';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
