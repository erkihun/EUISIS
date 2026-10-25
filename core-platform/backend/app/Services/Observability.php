<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lightweight operational figures (ADR 0011) kept in the cache for the day,
 * with no new infrastructure: slow web and API requests (by route), the
 * duration of background jobs (by job class) and failed jobs. API latency and
 * error rates come from the API call log the Core already keeps. A slow
 * request is also logged (route and duration only, never parameters).
 */
final class Observability
{
    private const TTL = 60 * 60 * 48;

    public static function slowRequest(string $route, string $method, int $ms, int $status): void
    {
        Log::warning('Slow request.', ['route' => $route, 'method' => $method, 'duration_ms' => $ms, 'status' => $status]);
        self::bump('core.observe.slow.'.now()->format('Ymd'), $method.' '.$route, $ms);
    }

    public static function job(string $class, int $ms, bool $failed): void
    {
        self::bump('core.observe.jobs.'.now()->format('Ymd'), $class, $ms, $failed);
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $day = now()->format('Ymd');
        $sort = fn (array $rows): array => collect($rows)->map(fn (array $r, string $k): array => ['name' => $k] + $r)->sortByDesc('max_ms')->take(15)->values()->all();
        $since = now()->subDay();
        $routes = DB::table('api_request_logs')->where('created_at', '>=', $since)->groupBy('route')
            ->selectRaw('route, count(*) as calls, avg(duration_ms) as mean_ms, max(duration_ms) as max_ms, sum(case when status >= 500 then 1 else 0 end) as errors')
            ->orderByDesc('max_ms')->limit(10)->get()->map(fn ($r): array => ['route' => $r->route, 'calls' => (int) $r->calls, 'mean_ms' => (int) round((float) $r->mean_ms),
                'max_ms' => (int) $r->max_ms, 'errors' => (int) $r->errors])->all();
        $verify = DB::table('api_request_logs')->where('created_at', '>=', $since)->where('route', 'like', '%verify%')
            ->selectRaw('count(*) as calls, avg(duration_ms) as mean_ms, max(duration_ms) as max_ms')->first();

        return [
            'slow_requests' => $sort((array) Cache::get('core.observe.slow.'.$day, [])),
            'jobs' => $sort((array) Cache::get('core.observe.jobs.'.$day, [])),
            'api_routes' => $routes,
            'verification' => ['calls' => (int) ($verify->calls ?? 0), 'mean_ms' => (int) round((float) ($verify->mean_ms ?? 0)), 'max_ms' => (int) ($verify->max_ms ?? 0)],
            'threshold_ms' => (int) config('core.slow_request_ms'),
        ];
    }

    private static function bump(string $key, string $name, int $ms, bool $failed = false): void
    {
        try {
            $rows = (array) Cache::get($key, []);
            $row = $rows[$name] ?? ['count' => 0, 'total_ms' => 0, 'max_ms' => 0, 'failed' => 0];
            $row['count']++;
            $row['total_ms'] += $ms;
            $row['max_ms'] = max($row['max_ms'], $ms);
            $row['failed'] += $failed ? 1 : 0;
            $row['mean_ms'] = intdiv($row['total_ms'], max(1, $row['count']));
            $rows[$name] = $row;
            Cache::put($key, array_slice($rows, -200, preserve_keys: true), self::TTL);
        } catch (\Throwable) {
            // Figures are best effort: a cache problem never fails the request or the job.
        }
    }
}
