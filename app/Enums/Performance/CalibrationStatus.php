<?php

declare(strict_types=1);

namespace App\Enums\Performance;

enum CalibrationStatus: string
{
    case Draft = 'DRAFT';
    case InProgress = 'IN_PROGRESS';
    case Finalized = 'FINALIZED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
