<?php

declare(strict_types=1);

/*
 * Transactional-outbox, change-feed and webhook throughput on a benchmark
 * database (docs/production-readiness/queue-readiness.md).
 *
 *   DB_DATABASE=aaemployeedb_scale3m php loadtest/integration/outbox-bench.php write-overhead [n]
 *       n employee updates through Eloquent (one transaction each), with the
 *       outbox on and then off: the added write latency of recording an event.
 *   DB_DATABASE=… php loadtest/integration/outbox-bench.php backlog [n]
 *       n employee updates in bulk transactions → n pending events (a bulk change).
 *   DB_DATABASE=… php loadtest/integration/outbox-bench.php publish
 *       publishes the whole backlog in this process; events/s.
 *   DB_DATABASE=… php loadtest/integration/outbox-bench.php subscribe <receiver-url> [count]
 *       count webhook subscriptions (one per synthetic client) to a LOCAL receiver.
 *   DB_DATABASE=… php loadtest/integration/outbox-bench.php deliver [seconds]
 *       runs the dispatcher in this process until nothing is due or time is up; deliveries/s.
 *   DB_DATABASE=… php loadtest/integration/outbox-bench.php status
 *
 * Webhook runs need INTEGRATION_WEBHOOKS_ENABLED=true INTEGRATION_WEBHOOK_ALLOW_HTTP=true
 * INTEGRATION_WEBHOOK_INTERNAL_HOSTS=127.0.0.1 — benchmark only; never point it at a partner.
 */

use App\Models\Employee;
use App\Models\ExternalApplication;
use App\Models\User;
use App\Services\Integration\Events\OutboxPublisher;
use App\Services\Integration\Webhooks\DeliveryDispatcher;
use App\Services\Integration\Webhooks\SubscriptionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment('production') || DB::table('employees')->where('employee_number', 'not like', 'SC-%')->limit(1)->exists()) {
    fwrite(STDERR, "Benchmark databases only.\n");
    exit(1);
}

$mode = $argv[1] ?? 'status';
$pending = fn (): int => (int) DB::table('integration_events')->where('status', 'pending')->count();
$sampleEmployees = fn (int $n): array => DB::table('employees')->where('employee_number', '~', '^SC-[0-9]+$')
    ->whereRaw('employee_number = ANY (?)', ['{'.implode(',', array_map(fn () => 'SC-'.random_int(0, 2_999_999), range(1, $n))).'}'])
    ->pluck('id')->all();

switch ($mode) {
    case 'write-overhead':
        $n = (int) ($argv[2] ?? 1000);
        $result = [];
        foreach ([true, false] as $enabled) {
            config(['integration.events.enabled' => $enabled]);
            $ids = $sampleEmployees($n);
            $times = [];
            foreach ($ids as $i => $id) {
                $employee = Employee::query()->find($id);
                $employee->middle_name = 'Bench'.random_int(0, 9_999_999); // a published field: emits employee.updated
                $t = hrtime(true);
                $employee->save();
                $times[] = (hrtime(true) - $t) / 1e6;
            }
            sort($times);
            $result[$enabled ? 'outbox_on' : 'outbox_off'] = ['writes' => count($times), 'p50_ms' => round($times[intdiv(count($times), 2)], 2),
                'p95_ms' => round($times[(int) (count($times) * 0.95)], 2), 'mean_ms' => round(array_sum($times) / max(1, count($times)), 2)];
        }
        echo json_encode($result, JSON_PRETTY_PRINT), PHP_EOL;
        break;

    case 'backlog':
        $n = (int) ($argv[2] ?? 100000);
        $t = microtime(true);
        $done = 0;
        while ($done < $n) {
            $ids = $sampleEmployees(min(500, $n - $done));
            DB::transaction(function () use ($ids): void {
                foreach (Employee::query()->whereIn('id', $ids)->get() as $employee) {
                    $employee->middle_name = 'Bench'.random_int(0, 9_999_999); // a published field: emits employee.updated
                    $employee->save();
                }
            });
            $done += count($ids);
        }
        printf("updated=%d in %.1fs (%.0f writes/s, one event each); pending=%d\n", $done, microtime(true) - $t, $done / (microtime(true) - $t), $pending());
        break;

    case 'publish':
        $publisher = app(OutboxPublisher::class);
        $before = $pending();
        $t = microtime(true);
        $totals = ['published' => 0, 'deliveries' => 0, 'batches' => 0, 'slowest_batch_s' => 0.0];
        do {
            $b = microtime(true);
            $batch = $publisher->publish();
            $totals['published'] += $batch['published'];
            $totals['deliveries'] += $batch['deliveries'];
            $totals['batches']++;
            $totals['slowest_batch_s'] = max($totals['slowest_batch_s'], round(microtime(true) - $b, 2));
        } while (($batch['published'] + $batch['rejected']) > 0);
        $s = microtime(true) - $t;
        printf("pending_before=%d published=%d deliveries=%d batches=%d in %.1fs → %.0f events/s; slowest batch %.2fs; pending_after=%d\n",
            $before, $totals['published'], $totals['deliveries'], $totals['batches'], $s, $totals['published'] / max(0.001, $s), $totals['slowest_batch_s'], $pending());
        break;

    case 'subscribe':
        $url = $argv[2] ?? 'http://127.0.0.1:9099/hook';
        $count = (int) ($argv[3] ?? 1);
        $actor = User::query()->firstOrCreate(['email' => 'sc-race@scale.invalid'], ['name' => 'Race harness', 'password' => bcrypt(Str::random(40)), 'status' => 'active']);
        $made = 0;
        foreach (ExternalApplication::query()->where('code', 'like', 'SC-K6-%')->where('organization_access', 'city')->orderBy('code')->limit($count)->get() as $application) {
            app(SubscriptionService::class)->create($application, 'k6 synthetic receiver', $url, ['employee.updated', 'employee.status_changed'], $actor);
            $made++;
        }
        echo "subscriptions created={$made}\n";
        break;

    case 'deliver':
        $seconds = (int) ($argv[2] ?? 120);
        $dispatcher = app(DeliveryDispatcher::class);
        $t = microtime(true);
        $totals = [];
        do {
            $counts = $dispatcher->run();
            foreach ($counts as $status => $n) {
                $totals[$status] = ($totals[$status] ?? 0) + $n;
            }
        } while (array_sum($counts) > 0 && microtime(true) - $t < $seconds);
        $s = microtime(true) - $t;
        $sent = ($totals['succeeded'] ?? 0) + ($totals['delivered'] ?? 0);
        printf("%s in %.1fs → %.0f delivered/s\n", json_encode($totals), $s, $sent / max(0.001, $s));
        // falls through to status
    case 'status':
        $by = DB::table('webhook_deliveries')->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status')->all();
        printf("outbox pending=%d published=%d | deliveries %s\n", $pending(),
            DB::table('integration_events')->where('status', 'published')->count(), json_encode($by));
        break;

    default:
        fwrite(STDERR, "unknown mode {$mode}\n");
        exit(1);
}
