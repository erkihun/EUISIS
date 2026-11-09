<?php

declare(strict_types=1);

/*
 * Samples database-side load during a test run, every INTERVAL seconds, and
 * prints a summary at the end (docs/production-readiness/load-test-results.md):
 * connections to the benchmark database by state, waiting locks, the oldest
 * running query and transaction, deadlocks, commits/rollbacks per second,
 * queue depth and the outbox backlog. Read-only.
 *
 *   DB_DATABASE=aaemployeedb_scale3m php loadtest/rig/sampler.php <seconds> [interval] > samples.csv
 * The summary goes to stderr.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$seconds = (int) ($argv[1] ?? 60);
$interval = (float) ($argv[2] ?? 2);
$db = DB::getDatabaseName();
$until = microtime(true) + $seconds;
$rows = [];
$prev = null;

echo "t,total,active,idle,idle_in_tx,waiting_locks,oldest_query_s,oldest_tx_s,deadlocks,commits_s,rollbacks_s,jobs,outbox_pending,max_connections\n";
$maxConnections = (int) DB::selectOne('show max_connections')->max_connections;
while (microtime(true) < $until) {
    $t = microtime(true);
    $a = DB::selectOne("
        SELECT count(*) AS total,
               count(*) FILTER (WHERE state = 'active') AS active,
               count(*) FILTER (WHERE state = 'idle') AS idle,
               count(*) FILTER (WHERE state LIKE 'idle in transaction%') AS idle_in_tx,
               count(*) FILTER (WHERE wait_event_type = 'Lock') AS waiting_locks,
               coalesce(extract(epoch FROM max(now() - query_start) FILTER (WHERE state = 'active')), 0) AS oldest_query,
               coalesce(extract(epoch FROM max(now() - xact_start)), 0) AS oldest_tx
        FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()", [$db]);
    $s = DB::selectOne('SELECT deadlocks, xact_commit, xact_rollback FROM pg_stat_database WHERE datname = ?', [$db]);
    $jobs = (int) DB::table('jobs')->count();
    $outbox = (int) DB::table('integration_events')->where('status', 'pending')->count();
    $commits = $prev ? ($s->xact_commit - $prev[1]->xact_commit) / ($t - $prev[0]) : 0;
    $rollbacks = $prev ? ($s->xact_rollback - $prev[1]->xact_rollback) / ($t - $prev[0]) : 0;
    $row = [round($t, 1), $a->total, $a->active, $a->idle, $a->idle_in_tx, $a->waiting_locks, round((float) $a->oldest_query, 2),
        round((float) $a->oldest_tx, 2), $s->deadlocks, round($commits), round($rollbacks), $jobs, $outbox, $maxConnections];
    echo implode(',', $row), "\n";
    $rows[] = $row;
    $prev = [$t, $s];
    usleep((int) max(0, ($interval - (microtime(true) - $t)) * 1e6));
}

if ($rows !== []) {
    $col = fn (int $i) => array_column($rows, $i);
    fwrite(STDERR, sprintf("samples=%d connections max=%d avg=%.1f (max_connections %d) | active max=%d | idle_in_tx max=%d | waiting_locks max=%d | oldest query max=%.2fs | oldest tx max=%.2fs | deadlocks +%d | commits/s avg=%.0f | rollbacks/s avg=%.1f | jobs max=%d | outbox pending max=%d end=%d\n",
        count($rows), max($col(1)), array_sum($col(1)) / count($rows), $maxConnections, max($col(2)), max($col(4)), max($col(5)), max($col(6)), max($col(7)),
        end($rows)[8] - $rows[0][8], array_sum($col(9)) / max(1, count($rows) - 1), array_sum($col(10)) / max(1, count($rows) - 1), max($col(11)), max($col(12)), end($rows)[12]));
}
