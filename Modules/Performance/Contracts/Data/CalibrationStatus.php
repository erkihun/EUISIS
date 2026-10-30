<?php

declare(strict_types=1);

namespace Modules\Performance\Contracts\Data;

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
