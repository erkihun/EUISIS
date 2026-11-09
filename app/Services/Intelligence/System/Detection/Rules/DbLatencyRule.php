<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Detection\Rules;

use App\Services\Intelligence\System\Detection\DetectionRule;
use App\Services\Intelligence\System\Detection\DetectionWindow;
use App\Services\Intelligence\System\Detection\Finding;
use App\Services\Intelligence\System\Telemetry\Metrics;

/** Database probe time against the configured limit or its own baseline (at least MIN_MS); plus long-running queries above their baseline. */
final class DbLatencyRule implements DetectionRule
{
    public const MIN_MS = 50;

    public function key(): string
    {
        return 'db_latency';
    }

    public function evaluate(DetectionWindow $window): array
    {
        $ping = $window->recent(Metrics::DB_PING_MS);
        if ($ping === null) {
            return [];
        }
        $history = $window->history(Metrics::DB_PING_MS);
        $severity = $window->severityFor($ping, $window->threshold('db_latency_ms'), $history, self::MIN_MS);

        $long = $window->recent(Metrics::DB_LONG_QUERIES);
        $longHistory = $window->history(Metrics::DB_LONG_QUERIES);
        if ($long !== null && $window->abnormallyHigh($long, $longHistory, 3) && $severity === null) {
            $severity = 'medium';
        }
        if ($severity === null) {
            return [];
        }
        $stats = $window->describe($history);

        return [new Finding(
            rule: $this->key(),
            component: Metrics::COMPONENT_DATABASE,
            dimension: null,
            severity: $severity,
            params: ['ping' => round($ping, 1), 'baseline' => $stats['median'] !== null ? round($stats['median'], 1) : null, 'long_queries' => (int) ($long ?? 0)],
            evidence: [
                DetectionWindow::evidence('ping_ms', round($ping, 1)),
                DetectionWindow::evidence('baseline_median_ms', $stats['median']),
                DetectionWindow::evidence('baseline_samples', $stats['n']),
                DetectionWindow::evidence('long_running_queries', $long),
                DetectionWindow::evidence('limit_ms', $window->threshold('db_latency_ms')),
            ],
            signals: [Metrics::DB_PING_MS, Metrics::DB_LONG_QUERIES],
            module: 'database',
        )];
    }
}
