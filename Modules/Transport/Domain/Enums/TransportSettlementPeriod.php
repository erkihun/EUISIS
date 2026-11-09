<?php

declare(strict_types=1);

namespace Modules\Transport\Domain\Enums;

enum TransportSettlementPeriod: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
