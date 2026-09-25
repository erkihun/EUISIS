<?php

declare(strict_types=1);

namespace App\Enums\Performance;

enum ReviewType: string
{
    case MidYear = 'MID_YEAR';
    case YearEnd = 'YEAR_END';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
