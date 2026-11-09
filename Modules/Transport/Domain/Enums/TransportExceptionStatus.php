<?php

declare(strict_types=1);

namespace Modules\Transport\Domain\Enums;

enum TransportExceptionStatus: string
{
    case Open = 'open';
    case Reviewed = 'reviewed';
    case Dismissed = 'dismissed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
