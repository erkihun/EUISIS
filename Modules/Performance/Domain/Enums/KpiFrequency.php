<?php

declare(strict_types=1);

namespace Modules\Performance\Domain\Enums;

enum KpiFrequency: string
{
    case Daily = 'DAILY';
    case Weekly = 'WEEKLY';
    case Monthly = 'MONTHLY';
    case Quarterly = 'QUARTERLY';
    case SemiAnnual = 'SEMI_ANNUAL';
    case Annual = 'ANNUAL';
    case Custom = 'CUSTOM';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
