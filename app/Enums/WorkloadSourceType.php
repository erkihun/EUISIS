<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where the workload figures of a manpower-plan line came from (brief §19).
 * An approved plan never carries an unexplained number.
 */
enum WorkloadSourceType: string
{
    case HistoricalTransactions = 'historical_transactions';
    case ApprovedForecast = 'approved_forecast';
    case AdministrativeInput = 'administrative_input';
    case WorkMeasurementStudy = 'work_measurement_study';
    case ServiceTarget = 'service_target';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
