<?php

declare(strict_types=1);

namespace App\Services\Intelligence\Contracts;

use Carbon\CarbonImmutable;

/**
 * What the business domain may ask the technical domain (brief §45,
 * cross-domain correlation): aggregate request outcomes for a route group in
 * a window, and whether a technical incident touched it. Never a log line,
 * never a stack trace. The telemetry implementation is bound when platform
 * telemetry exists; otherwise a null source answers "unknown" and no
 * correlation is claimed.
 */
interface TechnicalSignalSource
{
    /**
     * Route groups a business flow depends on (see RouteGroups).
     *
     * @return array{known: bool, requests: int, failures: int, failure_rate_percent: ?float, incidents: int}
     */
    public function failuresFor(string $routeGroup, CarbonImmutable $from, CarbonImmutable $to): array;
}
