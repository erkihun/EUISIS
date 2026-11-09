<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Load-balancer probes (docs/load-balancing.md).
 *
 *  - live:  the PHP process can serve a request. No dependency is touched,
 *           so a database outage never makes the orchestrator restart nodes.
 *  - ready: the node can do useful work — database, cache, queue store and
 *           private file storage answer. A failing node is taken out of
 *           rotation (503) without being restarted.
 *
 * Responses name the check and ok/fail only: never hosts, credentials,
 * versions or error text (those go to the log).
 */
class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return $this->respond(['status' => 'ok']);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => fn () => DB::connection()->select('select 1'),
            'cache' => fn () => Cache::store()->get('health:probe'),
            'queue' => fn () => $this->probeQueue(),
            'storage' => fn () => $this->probeStorage(),
        ];

        $results = [];
        foreach ($checks as $name => $check) {
            try {
                $check();
                $results[$name] = 'ok';
            } catch (\Throwable $e) {
                $results[$name] = 'fail';
                Log::warning("Readiness check failed: {$name}", ['exception' => $e::class, 'message' => $e->getMessage()]);
            }
        }

        $ready = ! in_array('fail', $results, true);

        return $this->respond(['status' => $ready ? 'ok' : 'fail', 'checks' => $results], $ready ? 200 : 503);
    }

    /**
     * The database queue answers with one indexed row probe instead of
     * Queue::size(), which is COUNT(*) over the whole jobs table — a probe
     * that gets slower exactly when a backlog builds up (docs/production-readiness/queue-readiness.md).
     */
    private function probeQueue(): void
    {
        $connection = (string) config('queue.default');
        $settings = (array) config("queue.connections.{$connection}", []);

        if (($settings['driver'] ?? null) === 'database') {
            DB::connection($settings['connection'] ?? null)->table($settings['table'] ?? 'jobs')->limit(1)->exists();

            return;
        }

        Queue::connection()->size('default');
    }

    /**
     * A remote disk throws when it is unreachable; a local (or mounted shared)
     * disk rarely does, so its root must also be a writable directory — a
     * read-only or broken mount must not report ok. (An unmounted path that
     * falls back to the node's own directory still passes: the deployment
     * must check the mount itself, docs/production-readiness/load-balancer-readiness.md.)
     */
    private function probeStorage(): void
    {
        $disk = Storage::disk('local');
        $disk->exists('health/.probe');

        if (config('filesystems.disks.local.driver') === 'local') {
            $root = $disk->path('');
            if (! is_dir($root) || ! is_writable($root)) {
                throw new \RuntimeException('Private storage root is not a writable directory.');
            }
        }
    }

    /** @param array<string, mixed> $body */
    private function respond(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, [
            'Cache-Control' => 'no-store',
        ]);
    }
}
