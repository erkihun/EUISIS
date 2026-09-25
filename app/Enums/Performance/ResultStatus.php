<?php

declare(strict_types=1);

namespace App\Enums\Performance;

/**
 * Final results appear to the employee only once RELEASED (when release is required).
 */
enum ResultStatus: string
{
    case Calculated = 'CALCULATED';
    case PendingCalibration = 'PENDING_CALIBRATION';
    case Finalized = 'FINALIZED';
    case PendingRelease = 'PENDING_RELEASE';
    case Released = 'RELEASED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
