<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Telemetry;

use App\Enums\AuditEventType;
use App\Services\Backup\BackupStatusService;
use App\Support\Features;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Communication\Contracts\Data\CommunicationTelemetry;
use Modules\Transport\Contracts\TransportOperationalMetrics;
use Predis\Client;
use Throwable;

/**
 * Samples the platform every five minutes into intelligence_signals (brief
 * §25, §37–42): request counters flushed from the cache, database and cache
 * probes, Redis when it is configured, queue depth and failures, a scheduler
 * heartbeat, storage, backup health, integration outcomes and security
 * counters. Every probe is isolated: a probe that fails writes nothing, and
 * nothing is ever estimated — a missing sample reads as UNKNOWN downstream.
 */
final class TelemetryCollector
{
    /** @var list<string> */
    private array $failures = [];

    public function __construct(
        private readonly SignalRepository $signals,
        private readonly RequestCounters $counters,
    ) {}

    /** @return array{written: int, probes: int, failed: list<string>} */
    public function collect(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $bucket = $this->bucket($now);
        $this->failures = [];
        $samples = [];
        $probes = [
            'requests' => fn (): array => $this->requests($now),
            'database' => fn (): array => $this->database($bucket),
            'cache' => fn (): array => $this->cache($bucket),
            'redis' => fn (): array => $this->redis($bucket),
            'queue' => fn (): array => $this->queue($now, $bucket),
            'scheduler' => fn (): array => [$this->sample(Metrics::SCHEDULER_HEARTBEAT, Metrics::COMPONENT_SCHEDULER, 1, $bucket)],
            'storage' => fn (): array => $this->storage($bucket),
            'backups' => fn (): array => $this->backups($bucket),
            'integrations' => fn (): array => $this->integrations($now, $bucket),
            'security' => fn (): array => $this->security($now, $bucket),
            'sessions' => fn (): array => $this->sessions($now, $bucket),
            'transport' => fn (): array => Features::enabled('transport') ? $this->transport($now, $bucket) : [],
        ];

        foreach ($probes as $name => $probe) {
            try {
                array_push($samples, ...$probe());
            } catch (Throwable $e) {
                report($e);
                $this->failures[] = $name;
            }
        }

        return ['written' => $this->signals->write($samples), 'probes' => count($probes), 'failed' => $this->failures];
    }

    /** Flushes completed minutes of request counters into five-minute buckets per route group. */
    private function requests(CarbonImmutable $now): array
    {
        $flushMinutes = max(5, (int) config('intelligence.telemetry.flush_minutes', 10));
        $lastComplete = $this->counters->minuteKey($now->getTimestamp()) - 60;
        $minutes = [];
        for ($m = $lastComplete - ($flushMinutes - 1) * 60; $m <= $lastComplete; $m += 60) {
            $minutes[] = $m;
        }
        $drained = $this->counters->drain($minutes);

        // minute → bucket (5 min) → group → fields
        $buckets = [];
        foreach ($drained as $minute => $groups) {
            $bucketStart = $minute - ($minute % (DetectionBucket::SECONDS));
            foreach ($groups as $group => $fields) {
                foreach ($fields as $field => $value) {
                    $buckets[$bucketStart][$group][$field] = ($buckets[$bucketStart][$group][$field] ?? 0) + $value;
                }
            }
        }

        $samples = [];
        foreach ($buckets as $bucketStart => $groups) {
            // Carbon 3 reads a bare timestamp as UTC; buckets are stored and queried in the app timezone.
            $at = CarbonImmutable::createFromTimestamp($bucketStart, date_default_timezone_get());
            foreach ($groups as $group => $f) {
                $total = (int) ($f['total'] ?? 0);
                $map = [
                    Metrics::REQUEST_TOTAL => 'total', Metrics::REQUEST_2XX => 's2xx', Metrics::REQUEST_3XX => 's3xx', Metrics::REQUEST_4XX => 's4xx',
                    Metrics::REQUEST_5XX => 's5xx', Metrics::REQUEST_401 => 's401', Metrics::REQUEST_403 => 's403', Metrics::REQUEST_429 => 's429', Metrics::REQUEST_SLOW => 'slow',
                ];
                foreach ($map as $metric => $field) {
                    $samples[] = $this->sample($metric, Metrics::COMPONENT_APPLICATION, (int) ($f[$field] ?? 0), $at, $group, 'count', max(1, $total));
                }
                if ($total > 0) {
                    $samples[] = $this->sample(Metrics::REQUEST_LATENCY_AVG_MS, Metrics::COMPONENT_APPLICATION, round(($f['dur_sum'] ?? 0) / $total, 1), $at, $group, 'ms', $total);
                    $samples[] = $this->sample(Metrics::REQUEST_LATENCY_P95_MS, Metrics::COMPONENT_APPLICATION, $this->p95($f, $total), $at, $group, 'ms', $total);
                    $samples[] = $this->sample(Metrics::REQUEST_ERROR_RATE, Metrics::COMPONENT_APPLICATION, round(100 * (int) ($f['s5xx'] ?? 0) / $total, 2), $at, $group, 'percent', $total);
                }
            }
        }

        return $samples;
    }

    /** The histogram bucket's upper bound at which 95% of sampled requests are reached. */
    private function p95(array $fields, int $total): float
    {
        $bounds = ['b100' => 100, 'b300' => 300, 'b1000' => 1000, 'b3000' => 3000, 'binf' => 5000];
        $cumulative = 0;
        foreach ($bounds as $field => $bound) {
            $cumulative += (int) ($fields[$field] ?? 0);
            if ($cumulative >= 0.95 * $total) {
                return (float) $bound;
            }
        }

        return 5000.0;
    }

    private function database(CarbonImmutable $bucket): array
    {
        $started = microtime(true);
        DB::select('select 1');
        $samples = [$this->sample(Metrics::DB_PING_MS, Metrics::COMPONENT_DATABASE, round((microtime(true) - $started) * 1000, 2), $bucket, null, 'ms')];
        $longSeconds = max(1, (int) config('intelligence.telemetry.long_query_seconds', 30));

        try {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'pgsql') {
                $row = DB::selectOne("select count(*) filter (where state = 'active') as active, count(*) as total from pg_stat_activity where datname = current_database()");
                $max = (int) (DB::selectOne('show max_connections')->max_connections ?? 0);
                $long = (int) (DB::selectOne("select count(*) as c from pg_stat_activity where state = 'active' and query_start < now() - make_interval(secs => ?) and pid <> pg_backend_pid()", [$longSeconds])->c ?? 0);
                $waiting = (int) (DB::selectOne('select count(*) as c from pg_locks where not granted')->c ?? 0);
                $deadlocks = (int) (DB::selectOne('select deadlocks from pg_stat_database where datname = current_database()')->deadlocks ?? 0);
                $total = (int) ($row->total ?? 0);
                $samples[] = $this->sample(Metrics::DB_CONNECTIONS_ACTIVE, Metrics::COMPONENT_DATABASE, $total, $bucket, null, 'count');
                if ($max > 0) {
                    $samples[] = $this->sample(Metrics::DB_CONNECTIONS_MAX, Metrics::COMPONENT_DATABASE, $max, $bucket, null, 'count');
                    $samples[] = $this->sample(Metrics::DB_CONNECTIONS_PERCENT, Metrics::COMPONENT_DATABASE, round(100 * $total / $max, 1), $bucket, null, 'percent');
                }
                $samples[] = $this->sample(Metrics::DB_LONG_QUERIES, Metrics::COMPONENT_DATABASE, $long, $bucket, null, 'count');
                $samples[] = $this->sample(Metrics::DB_WAITING_LOCKS, Metrics::COMPONENT_DATABASE, $waiting, $bucket, null, 'count');
                $samples[] = $this->sample(Metrics::DB_DEADLOCKS_TOTAL, Metrics::COMPONENT_DATABASE, $deadlocks, $bucket, null, 'count');
            } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
                $connected = (int) (DB::selectOne("show status like 'Threads_connected'")->Value ?? 0);
                $max = (int) (DB::selectOne("show variables like 'max_connections'")->Value ?? 0);
                $long = (int) (DB::selectOne("select count(*) as c from information_schema.processlist where command <> 'Sleep' and time > ?", [$longSeconds])->c ?? 0);
                $samples[] = $this->sample(Metrics::DB_CONNECTIONS_ACTIVE, Metrics::COMPONENT_DATABASE, $connected, $bucket, null, 'count');
                if ($max > 0) {
                    $samples[] = $this->sample(Metrics::DB_CONNECTIONS_MAX, Metrics::COMPONENT_DATABASE, $max, $bucket, null, 'count');
                    $samples[] = $this->sample(Metrics::DB_CONNECTIONS_PERCENT, Metrics::COMPONENT_DATABASE, round(100 * $connected / $max, 1), $bucket, null, 'percent');
                }
                $samples[] = $this->sample(Metrics::DB_LONG_QUERIES, Metrics::COMPONENT_DATABASE, $long, $bucket, null, 'count');
            }
            // sqlite exposes none of this: the figures stay UNKNOWN.
        } catch (Throwable $e) {
            // Statistics views may be denied to the application role; the ping still counts.
            $this->failures[] = 'database_statistics';
        }

        return $samples;
    }

    private function cache(CarbonImmutable $bucket): array
    {
        $key = 'intel:probe:'.$bucket->getTimestamp();
        try {
            $started = microtime(true);
            Cache::put($key, 'ok', 60);
            $ok = Cache::get($key) === 'ok';
            Cache::forget($key);
            $ms = round((microtime(true) - $started) * 1000, 2);

            return [
                $this->sample(Metrics::CACHE_AVAILABLE, Metrics::COMPONENT_CACHE, $ok ? 1 : 0, $bucket),
                $this->sample(Metrics::CACHE_PING_MS, Metrics::COMPONENT_CACHE, $ms, $bucket, null, 'ms'),
            ];
        } catch (Throwable) {
            return [$this->sample(Metrics::CACHE_AVAILABLE, Metrics::COMPONENT_CACHE, 0, $bucket)];
        }
    }

    /** Only when Redis is part of this installation; otherwise its state is UNKNOWN, never green. */
    private function redis(CarbonImmutable $bucket): array
    {
        $uses = in_array('redis', [config('cache.default'), config('queue.default'), config('session.driver'), config('broadcasting.default')], true);
        if (! $uses || ! extension_loaded('redis') && ! class_exists(Client::class)) {
            return [];
        }
        try {
            $started = microtime(true);
            Redis::connection()->ping();

            return [
                $this->sample(Metrics::REDIS_AVAILABLE, Metrics::COMPONENT_REDIS, 1, $bucket),
                $this->sample(Metrics::REDIS_PING_MS, Metrics::COMPONENT_REDIS, round((microtime(true) - $started) * 1000, 2), $bucket, null, 'ms'),
            ];
        } catch (Throwable) {
            return [$this->sample(Metrics::REDIS_AVAILABLE, Metrics::COMPONENT_REDIS, 0, $bucket)];
        }
    }

    private function queue(CarbonImmutable $now, CarbonImmutable $bucket): array
    {
        $samples = [];
        $connection = (string) config('queue.default');

        // The database queue tables are read whenever they exist (the sync connection in tests has them too); Redis is asked for its sizes.
        if ($connection !== 'redis' && Schema::hasTable('jobs')) {
            $rows = DB::table('jobs')->selectRaw('queue, count(*) as pending, min(available_at) as oldest')->whereNull('reserved_at')->groupBy('queue')->get();
            foreach ($rows as $row) {
                $samples[] = $this->sample(Metrics::QUEUE_PENDING, Metrics::COMPONENT_QUEUE, (int) $row->pending, $bucket, (string) $row->queue, 'count');
                $samples[] = $this->sample(Metrics::QUEUE_OLDEST_AGE_SECONDS, Metrics::COMPONENT_QUEUE, max(0, $now->getTimestamp() - (int) $row->oldest), $bucket, (string) $row->queue, 'seconds');
            }
            $reserved = DB::table('jobs')->selectRaw('queue, count(*) as reserved')->whereNotNull('reserved_at')->groupBy('queue')->pluck('reserved', 'queue');
            foreach ($reserved as $queue => $count) {
                $samples[] = $this->sample(Metrics::QUEUE_RESERVED, Metrics::COMPONENT_QUEUE, (int) $count, $bucket, (string) $queue, 'count');
            }
            foreach (['default', 'notifications', 'long'] as $queue) {
                if (! $rows->contains(fn ($r): bool => (string) $r->queue === $queue)) {
                    $samples[] = $this->sample(Metrics::QUEUE_PENDING, Metrics::COMPONENT_QUEUE, 0, $bucket, $queue, 'count');
                }
            }
        } elseif ($connection === 'redis') {
            foreach (['default', 'notifications', 'long'] as $queue) {
                $samples[] = $this->sample(Metrics::QUEUE_PENDING, Metrics::COMPONENT_QUEUE, Queue::connection()->size($queue), $bucket, $queue, 'count');
            }
        }

        if (Schema::hasTable('failed_jobs')) {
            $samples[] = $this->sample(Metrics::QUEUE_FAILED_5M, Metrics::COMPONENT_QUEUE, DB::table('failed_jobs')->where('failed_at', '>=', $now->subMinutes(5)->toDateTimeString())->count(), $bucket, null, 'count');
            $samples[] = $this->sample(Metrics::QUEUE_FAILED_TOTAL, Metrics::COMPONENT_QUEUE, DB::table('failed_jobs')->count(), $bucket, null, 'count');
        }

        return $samples;
    }

    private function storage(CarbonImmutable $bucket): array
    {
        $samples = [];
        try {
            $path = 'intelligence/probe-'.$bucket->getTimestamp().'.txt';
            Storage::disk('local')->put($path, 'ok');
            $ok = Storage::disk('local')->exists($path);
            Storage::disk('local')->delete($path);
            $samples[] = $this->sample(Metrics::STORAGE_WRITABLE, Metrics::COMPONENT_STORAGE, $ok ? 1 : 0, $bucket);
        } catch (Throwable) {
            $samples[] = $this->sample(Metrics::STORAGE_WRITABLE, Metrics::COMPONENT_STORAGE, 0, $bucket);
        }
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        if (is_float($free) && is_float($total) && $total > 0) {
            $samples[] = $this->sample(Metrics::STORAGE_FREE_PERCENT, Metrics::COMPONENT_STORAGE, round(100 * $free / $total, 1), $bucket, null, 'percent');
        }

        return $samples;
    }

    private function backups(CarbonImmutable $bucket): array
    {
        if (! (bool) config('backup.enabled', false)) {
            return [];
        }
        $health = app(BackupStatusService::class)->health();
        $state = match ($health->overallStatus) {
            'HEALTHY' => Metrics::HEALTH_VALUES['healthy'],
            'WARNING' => Metrics::HEALTH_VALUES['degraded'],
            'CRITICAL' => Metrics::HEALTH_VALUES['unhealthy'],
            default => Metrics::HEALTH_VALUES['unknown'],
        };
        $samples = [$this->sample(Metrics::BACKUP_HEALTH, Metrics::COMPONENT_BACKUPS, $state, $bucket)];
        $age = $health->timestamps['backup_age_seconds'] ?? null;
        if (is_int($age)) {
            $samples[] = $this->sample(Metrics::BACKUP_AGE_HOURS, Metrics::COMPONENT_BACKUPS, round($age / 3600, 1), $bucket, null, 'hours');
        }

        return $samples;
    }

    private function integrations(CarbonImmutable $now, CarbonImmutable $bucket): array
    {
        $samples = [];
        $since = $now->subMinutes(5)->toDateTimeString();

        if (Schema::hasTable('external_service_usages')) {
            $rows = DB::table('external_service_usages')->selectRaw("provider, count(*) as calls, sum(case when status = 'failed' then 1 else 0 end) as failures")
                ->where('occurred_at', '>=', $since)->groupBy('provider')->get();
            foreach ($rows as $row) {
                $samples[] = $this->sample(Metrics::INTEGRATION_CALLS, Metrics::COMPONENT_INTEGRATIONS, (int) $row->calls, $bucket, (string) $row->provider, 'count');
                $samples[] = $this->sample(Metrics::INTEGRATION_FAILURES, Metrics::COMPONENT_INTEGRATIONS, (int) $row->failures, $bucket, (string) $row->provider, 'count');
            }
        }
        if (Schema::hasTable('api_request_logs')) {
            $row = DB::table('api_request_logs')->selectRaw('count(*) as calls, sum(case when success then 0 else 1 end) as failures')->where('requested_at', '>=', $since)->first();
            $samples[] = $this->sample(Metrics::INTEGRATION_API_REQUESTS, Metrics::COMPONENT_INTEGRATIONS, (int) ($row->calls ?? 0), $bucket, null, 'count');
            $samples[] = $this->sample(Metrics::INTEGRATION_API_FAILURES, Metrics::COMPONENT_INTEGRATIONS, (int) ($row->failures ?? 0), $bucket, null, 'count');
        }
        if (Schema::hasTable('integration_events')) {
            $pending = DB::table('integration_events')->where('status', 'pending');
            $oldest = (clone $pending)->min('created_at');
            $samples[] = $this->sample(Metrics::INTEGRATION_OUTBOX_PENDING, Metrics::COMPONENT_INTEGRATIONS, (int) $pending->count(), $bucket, null, 'count');
            $samples[] = $this->sample(Metrics::INTEGRATION_OUTBOX_OLDEST_SECONDS, Metrics::COMPONENT_INTEGRATIONS, $oldest === null ? 0 : max(0, $now->getTimestamp() - CarbonImmutable::parse($oldest)->getTimestamp()), $bucket, null, 'seconds');
            $samples[] = $this->sample(Metrics::INTEGRATION_WEBHOOK_DEAD_TOTAL, Metrics::COMPONENT_INTEGRATIONS, (int) DB::table('webhook_deliveries')->where('status', 'dead')->count(), $bucket, null, 'count');
            $failures = DB::table('webhook_delivery_attempts')
                ->join('webhook_deliveries', 'webhook_deliveries.id', '=', 'webhook_delivery_attempts.webhook_delivery_id')
                ->join('webhook_subscriptions', 'webhook_subscriptions.id', '=', 'webhook_deliveries.webhook_subscription_id')
                ->join('external_applications', 'external_applications.id', '=', 'webhook_subscriptions.external_application_id')
                ->where('webhook_delivery_attempts.started_at', '>=', $since)
                ->where('webhook_delivery_attempts.outcome', '!=', 'succeeded')
                ->selectRaw('external_applications.code as code, count(*) as failures')
                ->groupBy('external_applications.code')->get();
            foreach ($failures as $row) {
                $samples[] = $this->sample(Metrics::INTEGRATION_WEBHOOK_FAILURES, Metrics::COMPONENT_INTEGRATIONS, (int) $row->failures, $bucket, (string) $row->code, 'count');
            }
        }
        if (class_exists(CommunicationTelemetry::class)) {
            $samples[] = $this->sample(Metrics::INTEGRATION_BROADCAST_FAILURES_TOTAL, Metrics::COMPONENT_INTEGRATIONS, (int) Cache::get(CommunicationTelemetry::BROADCAST_FAILURES_KEY, 0), $bucket, null, 'count');
        }

        return $samples;
    }

    /** Counts only: how many MFA challenges failed in the window. Login failures are not audited (NEEDS_DATA); 401/403/429 come from the request counters. */
    private function security(CarbonImmutable $now, CarbonImmutable $bucket): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return [];
        }
        $count = DB::table('audit_logs')->where('event_type', AuditEventType::MfaChallengeFailed->value)->where('created_at', '>=', $now->subMinutes(5)->toDateTimeString())->count();

        return [$this->sample(Metrics::AUTH_MFA_FAILURES, Metrics::COMPONENT_SECURITY, $count, $bucket, null, 'count')];
    }

    private function sessions(CarbonImmutable $now, CarbonImmutable $bucket): array
    {
        if ((string) config('session.driver') !== 'database' || ! Schema::hasTable((string) config('session.table', 'sessions'))) {
            return [];
        }
        $count = DB::table((string) config('session.table', 'sessions'))->whereNotNull('user_id')->where('last_activity', '>=', $now->subMinutes(15)->getTimestamp())->count();

        return [$this->sample(Metrics::SESSIONS_ACTIVE, Metrics::COMPONENT_APPLICATION, $count, $bucket, null, 'count')];
    }

    private function bucket(CarbonImmutable $now): CarbonImmutable
    {
        $minute = (int) floor($now->minute / 5) * 5;

        return $now->setTime($now->hour, $minute, 0);
    }

    /** @return array{metric: string, component: string, dimension: ?string, value: int|float, unit: ?string, sample_count: int, bucket_start: CarbonImmutable} */
    private function sample(string $metric, string $component, int|float $value, CarbonImmutable $bucket, ?string $dimension = null, ?string $unit = null, int $sampleCount = 1): array
    {
        return ['metric' => $metric, 'component' => $component, 'dimension' => $dimension, 'value' => $value, 'unit' => $unit, 'sample_count' => $sampleCount, 'bucket_start' => $bucket];
    }

    /**
     * The scanner fleet and the boarding backlog: devices that fell silent
     * past the configured timeout, offline boardings waiting to sync and
     * how old the oldest is, trips left open past their day, and the last
     * quarter hour of accepted and refused scans. Counts only.
     */
    private function transport(CarbonImmutable $now, CarbonImmutable $bucket): array
    {
        $metrics = app(TransportOperationalMetrics::class)->snapshot($now);
        if ($metrics === null) {
            return [];
        }

        return [
            $this->sample(Metrics::TRANSPORT_DEVICES_ACTIVE, Metrics::COMPONENT_TRANSPORT, $metrics->activeDevices, $bucket, null, 'count'),
            $this->sample(Metrics::TRANSPORT_DEVICES_SILENT, Metrics::COMPONENT_TRANSPORT, $metrics->silentDevices, $bucket, null, 'count'),
            $this->sample(Metrics::TRANSPORT_OFFLINE_PENDING, Metrics::COMPONENT_TRANSPORT, $metrics->offlineBoardingsPending, $bucket, null, 'count'),
            $this->sample(Metrics::TRANSPORT_OFFLINE_OLDEST_MINUTES, Metrics::COMPONENT_TRANSPORT, $metrics->oldestOfflineBoardingMinutes, $bucket, null, 'minutes'),
            $this->sample(Metrics::TRANSPORT_STALE_OPEN_TRIPS, Metrics::COMPONENT_TRANSPORT, $metrics->staleOpenTrips, $bucket, null, 'count'),
            $this->sample(Metrics::TRANSPORT_BOARDINGS_15M, Metrics::COMPONENT_TRANSPORT, $metrics->boardingsLast15Minutes, $bucket, null, 'count'),
            $this->sample(Metrics::TRANSPORT_REFUSALS_15M, Metrics::COMPONENT_TRANSPORT, $metrics->refusalsLast15Minutes, $bucket, null, 'count'),
        ];
    }
}
