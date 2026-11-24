<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Ports;

/**
 * Optional indicators owned by another module, read through that module's
 * public query contract. A source that is missing, disabled or failing is
 * skipped: narrative reporting never depends on one.
 */
interface MetricSource
{
    /** A stable name, e.g. "service_monitoring". */
    public function key(): string;

    public function isAvailable(): bool;

    /**
     * @param  list<string>  $organizationIds
     * @return list<array{metric_key: string, definition: string, value: float|int|null, unit: ?string}>
     */
    public function capture(array $organizationIds, string $periodStart, string $periodEnd): array;
}
