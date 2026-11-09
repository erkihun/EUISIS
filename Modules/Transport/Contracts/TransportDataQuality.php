<?php

declare(strict_types=1);

namespace Modules\Transport\Contracts;

/**
 * Installation-wide checks of transport master data and settlement set-up
 * (routes without schedules or stops, trips without a vehicle, providers
 * without a contract, and so on), for the data quality report.
 */
interface TransportDataQuality
{
    /**
     * One row per check: a stable code, the number of records affected (-1 when
     * the check itself failed) and its severity: critical, warning or info.
     *
     * @return list<array{code: string, count: int, severity: string}>
     */
    public function summary(): array;
}
