<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ApiRequestLog;
use App\Services\SystemHealth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Probes for load balancers and the external monitor (ADR 0012), registered
 * without the web middleware so a probe never starts a session.
 *
 *  - live:    the PHP process answers; no dependency is touched.
 *  - ready:   database, cache, queue store and private storage answer; 503 takes
 *             the node out of rotation. Check names and ok/fail only.
 *  - monitor: for the external monitor, with CORE_MONITOR_TOKEN as a bearer
 *             token: every System Health status and the figures alerts are set
 *             on (five-minute windows). No personal data, host, secret or
 *             error text; 404 while no token is configured.
 */
class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return $this->respond(['status' => 'ok']);
    }

    public function ready(): JsonResponse
    {
        $results = [];
        foreach ([
            'database' => fn () => DB::select('select 1'),
            'cache' => fn () => Cache::get('core.health.probe'),
            'queue' => fn () => config('queue.default') === 'database' ? DB::table((string) config('queue.connections.database.table', 'jobs'))->limit(1)->exists() : null,
            'storage' => function (): void {
                $root = Storage::disk('evidence')->path('');
                if (config('filesystems.disks.evidence.driver') === 'local' && ! (is_dir($root) && is_writable($root))) {
                    throw new RuntimeException('Private storage root is not a writable directory.');
                }
            },
        ] as $name => $check) {
            try {
                $check();
                $results[$name] = 'ok';
            } catch (Throwable $e) {
                $results[$name] = 'fail';
                Log::warning("Readiness check failed: {$name}", ['exception' => $e::class]);
            }
        }
        $ready = ! in_array('fail', $results, true);

        return $this->respond(['status' => $ready ? 'ok' : 'fail', 'checks' => $results], $ready ? 200 : 503);
    }

    public function monitor(Request $request, SystemHealth $health): JsonResponse
    {
        $token = (string) config('core.monitor_token');
        abort_if($token === '', 404);
        if (! hash_equals(hash('sha256', $token), hash('sha256', (string) $request->bearerToken()))) {
            return $this->respond(['message' => 'Unauthenticated.'], 401);
        }
        $checks = $health->checks();
        $since = now()->subMinutes(5);
        $calls = ApiRequestLog::query()->where('created_at', '>=', $since);
        $verify = (clone $calls)->where('route', 'like', '%verify%');
        $root = config('filesystems.disks.evidence.driver') === 'local' ? Storage::disk('evidence')->path('') : null;
        $free = $root !== null && is_dir($root) ? @disk_free_space($root) : false;
        $total = $root !== null && is_dir($root) ? @disk_total_space($root) : false;
        $queues = (array) ($checks['queue']['details']['queues'] ?? []);
        $stale = array_keys(array_filter((array) $checks['scheduler']['details'],
            fn (?string $last, string $command): bool => $last === null || now()->diffInMinutes($last, true) > (SystemHealth::SCHEDULED[$command] ?? 15), ARRAY_FILTER_USE_BOTH));

        return $this->respond([
            'status' => collect($checks)->contains(fn (array $c): bool => $c['status'] === 'error') ? 'error'
                : (collect($checks)->contains(fn (array $c): bool => in_array($c['status'], ['warning', 'unknown'], true)) ? 'warning' : 'ok'),
            'checked_at' => now()->toIso8601String(),
            // The deployed release (deploy/release/build-artifact.sh writes RELEASE next to backend/), to see a deployment or rollback land.
            'release' => is_file($file = base_path('../RELEASE')) ? trim((string) strtok((string) file_get_contents($file), "\n")) : null,
            'checks' => array_map(fn (array $c): string => $c['status'], $checks),
            'metrics' => [
                'api_calls_5m' => (clone $calls)->count(),
                'api_server_errors_5m' => (clone $calls)->where('status', '>=', 500)->count(),
                'api_p95_ms_5m' => $this->p95(clone $calls),
                'card_verifications_5m' => (clone $verify)->count(),
                'card_verification_server_errors_5m' => (clone $verify)->where('status', '>=', 500)->count(),
                'card_verification_p95_ms_5m' => $this->p95(clone $verify),
                'queue_waiting' => $checks['queue']['details']['waiting_jobs'] ?? null,
                'queue_oldest_minutes' => $queues === [] ? 0 : max(array_column($queues, 'oldest_age_minutes')),
                'queue_failed_jobs' => $checks['queue']['details']['failed_jobs'] ?? null,
                'queue_stuck_jobs' => $checks['queue']['details']['stuck_jobs'] ?? null,
                'scheduler_stale_commands' => $stale,
                'backup_status' => $checks['backup']['status'],
                'backup_age_hours' => $checks['backup']['details']['age_hours'] ?? null,
                'audit_last_verification_ok' => $checks['audit_integrity']['details']['last_verification_ok'] ?? null,
                'audit_unsealed_rows' => $checks['audit_integrity']['details']['unsealed'] ?? null,
                'audit_heads_ship_failed' => ($checks['audit_integrity']['details']['heads_ship_failed_at'] ?? null) !== null,
                'webhooks_pending' => $checks['webhooks']['details']['pending'] ?? null,
                'webhooks_failed' => $checks['webhooks']['details']['failed'] ?? null,
                'storage_free_percent' => $free !== false && $total ? round($free / $total * 100, 1) : null,
            ],
        ]);
    }

    private function p95(Builder $query): ?int
    {
        $durations = $query->orderByDesc('id')->limit(5000)->pluck('duration_ms')->map(fn ($ms): int => (int) $ms)->sort()->values();

        return $durations->isEmpty() ? null : $durations[(int) max(0, ceil($durations->count() * 0.95) - 1)];
    }

    /** @param array<string, mixed> $body */
    private function respond(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, ['Cache-Control' => 'no-store']);
    }
}
