<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System;

use App\Models\IntelligenceIncident;
use App\Services\Intelligence\Contracts\TechnicalSignalSource;
use App\Services\Intelligence\System\Telemetry\Metrics;
use App\Services\Intelligence\System\Telemetry\SignalRepository;
use Carbon\CarbonImmutable;

/**
 * Answers the business domain from collected telemetry: requests and server
 * failures of a route group in a window, and the incidents that touched it.
 * "Known" is true only when the group was actually sampled in the window;
 * otherwise nothing is claimed.
 */
final class TelemetrySignalSource implements TechnicalSignalSource
{
    public function __construct(private readonly SignalRepository $signals) {}

    public function failuresFor(string $routeGroup, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $series = $this->signals->series(Metrics::REQUEST_TOTAL, $routeGroup, $from, $to, 10000);
        if ($series === []) {
            return ['known' => false, 'requests' => 0, 'failures' => 0, 'failure_rate_percent' => null, 'incidents' => 0];
        }
        $requests = (int) array_sum(array_map(fn (array $s): float => $s['value'], $series));
        $failures = (int) $this->signals->sum(Metrics::REQUEST_5XX, $routeGroup, $from, $to);
        $incidents = IntelligenceIncident::query()
            ->where('endpoint', $routeGroup)
            ->where('first_seen_at', '<=', $to->toDateTimeString())
            ->where('last_seen_at', '>=', $from->toDateTimeString())
            ->count();

        return [
            'known' => true,
            'requests' => $requests,
            'failures' => $failures,
            'failure_rate_percent' => $requests > 0 ? round(100 * $failures / $requests, 2) : null,
            'incidents' => $incidents,
        ];
    }
}
