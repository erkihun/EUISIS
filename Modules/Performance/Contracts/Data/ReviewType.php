<?php

declare(strict_types=1);

namespace Modules\Performance\Contracts\Data;

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
