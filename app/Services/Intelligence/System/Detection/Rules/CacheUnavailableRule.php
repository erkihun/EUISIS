<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Detection\Rules;

use App\Services\Intelligence\System\Detection\DetectionRule;
use App\Services\Intelligence\System\Detection\DetectionWindow;
use App\Services\Intelligence\System\Detection\Finding;
use App\Services\Intelligence\System\Telemetry\Metrics;

/** A cache store that does not answer is a fact (critical); a slow one is judged against the configured limit or its baseline. */
final class CacheUnavailableRule implements DetectionRule
{
    public function key(): string
    {
        return 'cache_unavailable';
    }

    public function evaluate(DetectionWindow $window): array
    {
        $available = $window->recent(Metrics::CACHE_AVAILABLE);
        $ping = $window->recent(Metrics::CACHE_PING_MS);
        $limit = $window->threshold('cache_latency_ms');
        $store = (string) config('cache.default');

        if ($available !== null && $available < 1) {
            return [new Finding($this->key(), Metrics::COMPONENT_CACHE, null, 'critical', ['store' => $store],
                [DetectionWindow::evidence('available', 0), DetectionWindow::evidence('store', $store)], [Metrics::CACHE_AVAILABLE], module: 'cache')];
        }
        if ($ping !== null) {
            $severity = $window->severityFor($ping, $limit, $window->history(Metrics::CACHE_PING_MS), 20);
            if ($severity !== null) {
                return [new Finding($this->key(), Metrics::COMPONENT_CACHE, null, $severity === 'critical' ? 'high' : $severity, ['store' => $store, 'ping' => round($ping, 1)],
                    [DetectionWindow::evidence('ping_ms', round($ping, 1)), DetectionWindow::evidence('limit_ms', $limit), DetectionWindow::evidence('store', $store)], [Metrics::CACHE_PING_MS], module: 'cache')];
            }
        }

        return [];
    }
}
