<?php

declare(strict_types=1);

namespace App\Enums\Performance;

/**
 * Performance cycle lifecycle. CLOSED and CANCELLED are read-only.
 */
enum CycleStatus: string
{
    case Draft = 'DRAFT';
    case Planning = 'PLANNING';
    case Cascaded = 'CASCADED';
    case Agreement = 'AGREEMENT';
    case Active = 'ACTIVE';
    case MidYearReview = 'MID_YEAR_REVIEW';
    case YearEndReview = 'YEAR_END_REVIEW';
    case Calibration = 'CALIBRATION';
    case Finalized = 'FINALIZED';
    case Closed = 'CLOSED';
    case Cancelled = 'CANCELLED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
