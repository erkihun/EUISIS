<?php

declare(strict_types=1);

namespace App\Enums\Performance;

/**
 * Progress status for dashboards; derived from achievement vs thresholds, never from activity volume.
 */
enum KpiHealth: string
{
    case OnTrack = 'ON_TRACK';
    case AtRisk = 'AT_RISK';
    case OffTrack = 'OFF_TRACK';
    case NotReported = 'NOT_REPORTED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
