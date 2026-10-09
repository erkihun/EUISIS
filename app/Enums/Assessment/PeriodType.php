<?php

declare(strict_types=1);

namespace App\Enums\Assessment;

/**
 * Informational evaluation frequency of a form; a cycle sets the actual dates.
 */
enum PeriodType: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SixMonth = 'six_month';
    case Annual = 'annual';
    case Custom = 'custom';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
