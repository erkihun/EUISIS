<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Telemetry;

use App\Services\Intelligence\System\RouteGroups;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Per-minute HTTP counters kept in the cache and flushed into
 * intelligence_signals by the collector, so a request never writes to the
 * database for telemetry (brief §78).
 *
 * Failures (status ≥ 400) and slow responses are always counted exactly; they
 * are rare. Totals and latency buckets are counted exactly on an in-memory
 * cache store (redis, memcached, apcu, array) and SAMPLED one request in
 * `sampleEvery()` on the database or file store — a sampled request adds the
 * sample size — so a database-backed cache sees one write per twenty requests
 * instead of several per request. Sampled figures are marked approximate.
 */
final class RequestCounters
{
    public const PREFIX = 'intel:req';

    /** Latency histogram upper bounds in milliseconds; the last bucket is open. */
    public const BUCKETS = [100, 300, 1000, 3000];

    public const FIELDS = ['total', 's2xx', 's3xx', 's4xx', 's5xx', 's401', 's403', 's429', 'dur_sum', 'slow', 'b100', 'b300', 'b1000', 'b3000', 'binf'];

    /** A response slower than this is counted exactly whatever the sampling. */
    public const SLOW_MS = 1000;

    /** Minutes a counter lives in the cache if the collector never flushes it. */
    private const TTL_MINUTES = 90;

    /** Record one finished request. Never throws: telemetry must not break the request. */
    public function record(string $routeGroup, int $status, float $durationMs, ?int $minute = null): void
    {
        try {
            $bucket = $this->minuteKey($minute ?? time());
            $group = in_array($routeGroup, RouteGroups::all(), true) ? $routeGroup : RouteGroups::OTHER;
            $every = $this->sampleEvery();
            $sampled = $every === 1 || random_int(1, $every) === 1;

            if ($status >= 500) {
                $this->bump($bucket, $group, 's5xx', 1);
            } elseif ($status >= 400) {
                $this->bump($bucket, $group, 's4xx', 1);
                if (in_array($status, [401, 403, 429], true)) {
                    $this->bump($bucket, $group, 's'.$status, 1);
                }
            }
            if ($durationMs >= self::SLOW_MS) {
                $this->bump($bucket, $group, 'slow', 1);
            }

            if (! $sampled) {
                return;
            }
            $weight = $every;
            $this->bump($bucket, $group, 'total', $weight);
            if ($status < 400) {
                $this->bump($bucket, $group, $status >= 300 ? 's3xx' : 's2xx', $weight);
            }
            $this->bump($bucket, $group, 'dur_sum', (int) round($durationMs) * $weight);
            $this->bump($bucket, $group, $this->latencyBucket($durationMs), $weight);
        } catch (Throwable) {
            // A broken cache must never surface in a user's request.
        }
    }

    /**
     * Counters of the given minutes, per route group, and forgets them.
     *
     * @param  list<int>  $minutes  Unix timestamps (any second of the minute).
     * @return array<int, array<string, array<string, int>>> minuteStart => group => field => value
     */
    public function drain(array $minutes): array
    {
        $keys = [];
        foreach ($minutes as $minute) {
            $bucket = $this->minuteKey($minute);
            foreach (RouteGroups::all() as $group) {
                foreach (self::FIELDS as $field) {
                    $keys[] = $this->key($bucket, $group, $field);
                }
            }
        }
        if ($keys === []) {
            return [];
        }

        $values = Cache::many($keys);
        $out = [];
        foreach ($values as $key => $value) {
            if (! is_numeric($value) || (int) $value === 0) {
                continue;
            }
            [, , $bucket, $group, $field] = explode(':', (string) $key, 5);
            $out[(int) $bucket][$group][$field] = (int) $value;
        }
        Cache::deleteMultiple(array_keys(array_filter($values, fn ($v): bool => $v !== null)));

        return $out;
    }

    /** One in how many requests contributes to totals: 1 on an in-memory store. */
    public function sampleEvery(): int
    {
        $configured = (int) config('intelligence.telemetry.sample_every', 0);
        if ($configured > 0) {
            return $configured;
        }

        return in_array((string) config('cache.default'), ['redis', 'memcached', 'apc', 'apcu', 'array', 'octane', 'dynamodb'], true) ? 1 : 20;
    }

    public function latencyBucket(float $durationMs): string
    {
        foreach (self::BUCKETS as $bound) {
            if ($durationMs < $bound) {
                return 'b'.$bound;
            }
        }

        return 'binf';
    }

    /** The minute start (unix seconds) of a timestamp. */
    public function minuteKey(int $timestamp): int
    {
        return $timestamp - ($timestamp % 60);
    }

    private function bump(int $bucket, string $group, string $field, int $by): void
    {
        $key = $this->key($bucket, $group, $field);
        if (! Cache::add($key, $by, now()->addMinutes(self::TTL_MINUTES))) {
            Cache::increment($key, $by);
        }
    }

    private function key(int $bucket, string $group, string $field): string
    {
        return self::PREFIX.":{$bucket}:{$group}:{$field}";
    }
}
