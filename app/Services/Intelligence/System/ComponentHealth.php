<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System;

use App\Models\IntelligenceIncident;
use App\Services\Intelligence\IntelligenceSettings;
use App\Services\Intelligence\System\Telemetry\Metrics;
use App\Services\Intelligence\System\Telemetry\SignalRepository;
use Carbon\CarbonImmutable;

/**
 * The health state of each platform component (brief §25–26): healthy,
 * degraded, unhealthy or unknown. A state comes from the freshest sample of
 * the component (no sample within FRESH_MINUTES → unknown, never green) and
 * is raised by any active incident on the component. Nothing is estimated.
 */
final class ComponentHealth
{
    public const FRESH_MINUTES = 15;

    private const RANK = ['healthy' => 0, 'degraded' => 1, 'unhealthy' => 2, 'unknown' => 3];

    public function __construct(
        private readonly SignalRepository $signals,
        private readonly IntelligenceSettings $settings,
    ) {}

    /**
     * @return array<string, array{state: string, reason: ?string, detail: array<string, int|float|string|null>, incidents: int, worst: ?string, at: ?string}>
     */
    public function states(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $out = [];

        if (! $this->settings->telemetryEnabled()) {
            foreach (Metrics::components() as $component) {
                $out[$component] = ['state' => 'unknown', 'reason' => 'TELEMETRY_OFF', 'detail' => [], 'incidents' => 0, 'worst' => null, 'at' => null];
            }

            return $out;
        }

        $incidents = IntelligenceIncident::query()->whereIn('status', IntelligenceIncident::ACTIVE_STATUSES)
            ->selectRaw('component, count(*) as c, min(case severity when \'critical\' then 0 when \'high\' then 1 when \'medium\' then 2 when \'low\' then 3 else 4 end) as worst')
            ->groupBy('component')->get()->keyBy('component');
        $worstName = fn (?int $rank): ?string => $rank === null ? null : (['critical', 'high', 'medium', 'low', 'info'][$rank] ?? null);

        foreach (Metrics::components() as $component) {
            [$state, $reason, $detail, $at] = $this->sampleState($component, $now);
            $open = $incidents[$component] ?? null;
            $worst = $open === null ? null : $worstName((int) $open->worst);
            if ($worst !== null) {
                $fromIncident = in_array($worst, ['critical', 'high'], true) ? 'unhealthy' : 'degraded';
                if (self::RANK[$fromIncident] > self::RANK[$state] || $state === 'unknown') {
                    $state = $fromIncident;
                    $reason = null;
                }
            }
            $out[$component] = ['state' => $state, 'reason' => $reason, 'detail' => $detail, 'incidents' => (int) ($open->c ?? 0), 'worst' => $worst, 'at' => $at];
        }

        return $out;
    }

    /** Counts of components per state. @return array<string, int> */
    public function summary(array $states): array
    {
        $counts = ['healthy' => 0, 'degraded' => 0, 'unhealthy' => 0, 'unknown' => 0];
        foreach ($states as $s) {
            $counts[$s['state']]++;
        }

        return $counts;
    }

    /** @return array{0: string, 1: ?string, 2: array<string, int|float|string|null>, 3: ?string} */
    private function sampleState(string $component, CarbonImmutable $now): array
    {
        $fresh = $now->subMinutes(self::FRESH_MINUTES);
        $latest = fn (string $metric, ?string $dim = null, ?CarbonImmutable $since = null) => $this->signals->latest($metric, $dim, $since ?? $fresh);

        switch ($component) {
            case Metrics::COMPONENT_APPLICATION:
                $groups = $this->signals->dimensions(Metrics::REQUEST_TOTAL, $fresh);
                if ($groups === []) {
                    return ['unknown', 'UNKNOWN', [], null];
                }
                $requests = 0;
                $failures = 0;
                foreach ($groups as $g) {
                    $requests += (int) $this->signals->sum(Metrics::REQUEST_TOTAL, $g, $fresh);
                    $failures += (int) $this->signals->sum(Metrics::REQUEST_5XX, $g, $fresh);
                }

                return ['healthy', null, ['requests_15m' => $requests, 'failures_15m' => $failures, 'error_rate_percent' => $requests > 0 ? round(100 * $failures / $requests, 2) : null], $now->toIso8601String()];
            case Metrics::COMPONENT_DATABASE:
                $ping = $latest(Metrics::DB_PING_MS);
                if ($ping === null) {
                    return ['unknown', 'UNKNOWN', [], null];
                }

                return ['healthy', null, ['ping_ms' => $ping['value'], 'connections_percent' => $latest(Metrics::DB_CONNECTIONS_PERCENT)['value'] ?? null, 'long_running_queries' => $latest(Metrics::DB_LONG_QUERIES)['value'] ?? null], $ping['bucket_start']->toIso8601String()];
            case Metrics::COMPONENT_CACHE:
                $available = $latest(Metrics::CACHE_AVAILABLE);
                if ($available === null) {
                    return ['unknown', 'UNKNOWN', [], null];
                }

                return [$available['value'] >= 1 ? 'healthy' : 'unhealthy', null, ['available' => (int) $available['value'], 'ping_ms' => $latest(Metrics::CACHE_PING_MS)['value'] ?? null, 'store' => (string) config('cache.default')], $available['bucket_start']->toIso8601String()];
            case Metrics::COMPONENT_REDIS:
                $available = $latest(Metrics::REDIS_AVAILABLE);
                if ($available === null) {
                    return ['unknown', 'UNKNOWN', [], null];
                }

                return [$available['value'] >= 1 ? 'healthy' : 'unhealthy', null, ['available' => (int) $available['value'], 'ping_ms' => $latest(Metrics::REDIS_PING_MS)['value'] ?? null], $available['bucket_start']->toIso8601String()];
            case Metrics::COMPONENT_QUEUE:
                $queues = $this->signals->dimensions(Metrics::QUEUE_PENDING, $fresh);
                if ($queues === []) {
                    return ['unknown', 'UNKNOWN', [], null];
                }
                $pending = 0;
                $oldest = 0.0;
                foreach ($queues as $q) {
                    $pending += (int) ($latest(Metrics::QUEUE_PENDING, $q)['value'] ?? 0);
                    $oldest = max($oldest, (float) ($latest(Metrics::QUEUE_OLDEST_AGE_SECONDS, $q)['value'] ?? 0));
                }

                return ['healthy', null, ['pending' => $pending, 'oldest_minutes' => round($oldest / 60, 1), 'failed_5m' => $latest(Metrics::QUEUE_FAILED_5M)['value'] ?? null], $now->toIso8601String()];
            case Metrics::COMPONENT_SCHEDULER:
                $beat = $this->signals->latest(Metrics::SCHEDULER_HEARTBEAT);
                if ($beat === null) {
                    return ['unknown', 'UNKNOWN', [], null];
                }
                $age = (int) round(($now->getTimestamp() - $beat['bucket_start']->getTimestamp()) / 60);
                $state = $age < self::FRESH_MINUTES ? 'healthy' : ($age < 60 ? 'degraded' : 'unhealthy');

                return [$state, null, ['last_heartbeat_minutes' => $age], $beat['bucket_start']->toIso8601String()];
            case Metrics::COMPONENT_STORAGE:
                $writable = $latest(Metrics::STORAGE_WRITABLE);
                if ($writable === null) {
                    return ['unknown', 'UNKNOWN', [], null];
                }
                $free = $latest(Metrics::STORAGE_FREE_PERCENT)['value'] ?? null;
                $state = $writable['value'] < 1 ? 'unhealthy' : ($free !== null && $free <= 10 ? 'degraded' : 'healthy');

                return [$state, null, ['writable' => (int) $writable['value'], 'free_percent' => $free], $writable['bucket_start']->toIso8601String()];
            case Metrics::COMPONENT_BACKUPS:
                $health = $this->signals->latest(Metrics::BACKUP_HEALTH, null, $now->subMinutes(30));
                if ($health === null) {
                    return ['unknown', 'UNKNOWN', [], null];
                }
                $state = array_search((int) $health['value'], Metrics::HEALTH_VALUES, true) ?: 'unknown';

                return [$state, $state === 'unknown' ? 'UNKNOWN' : null, ['age_hours' => $latest(Metrics::BACKUP_AGE_HOURS, null, $now->subMinutes(30))['value'] ?? null], $health['bucket_start']->toIso8601String()];
            case Metrics::COMPONENT_INTEGRATIONS:
                $api = $latest(Metrics::INTEGRATION_API_REQUESTS);
                $providers = $this->signals->dimensions(Metrics::INTEGRATION_CALLS, $now->subHour());
                if ($api === null && $providers === []) {
                    return ['unknown', 'UNKNOWN', [], null];
                }

                return ['healthy', null, ['providers_active_1h' => count($providers), 'api_requests_5m' => $api['value'] ?? null, 'api_failures_5m' => $latest(Metrics::INTEGRATION_API_FAILURES)['value'] ?? null], $now->toIso8601String()];
            case Metrics::COMPONENT_SECURITY:
                $mfa = $latest(Metrics::AUTH_MFA_FAILURES);
                if ($mfa === null) {
                    return ['unknown', 'UNKNOWN', [], null];
                }
                $denied = 0;
                foreach ([Metrics::REQUEST_401, Metrics::REQUEST_403, Metrics::REQUEST_429] as $metric) {
                    $denied += (int) array_sum($this->signals->sumByDimension($metric, $fresh));
                }

                return ['healthy', null, ['denied_or_throttled_15m' => $denied, 'mfa_failures_5m' => $mfa['value']], $mfa['bucket_start']->toIso8601String()];
        }

        return ['unknown', 'UNKNOWN', [], null];
    }
}
