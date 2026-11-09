<?php

declare(strict_types=1);

namespace Modules\Transport\Contracts;

use Carbon\CarbonImmutable;
use Modules\Transport\Contracts\Data\TransportOperationsSnapshot;

/**
 * Installation-wide health of the scanner fleet and the boarding backlog, for
 * system telemetry. Counts only; no employee, device or trip identity.
 */
interface TransportOperationalMetrics
{
    /** Null when the transport tables are not installed. */
    public function snapshot(CarbonImmutable $now): ?TransportOperationsSnapshot;
}
