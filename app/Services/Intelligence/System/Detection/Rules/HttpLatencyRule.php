<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Detection\Rules;

use App\Services\Intelligence\System\Detection\DetectionRule;
use App\Services\Intelligence\System\Detection\DetectionWindow;
use App\Services\Intelligence\System\Detection\Finding;
use App\Services\Intelligence\System\Telemetry\Metrics;

/** Latest p95 latency per route group against the configured limit or the group's own baseline (at least MIN_MS). */
final class HttpLatencyRule implements DetectionRule
{
    public const MIN_MS = 300;

    public function key(): string
    {
        return 'http_latency';
    }

    public function evaluate(DetectionWindow $window): array
    {
        $findings = [];
        $limit = $window->threshold('http_p95_latency_ms');

        foreach ($window->signals->dimensions(Metrics::REQUEST_LATENCY_P95_MS, $window->now->subMinutes(15)) as $group) {
            $p95 = $window->recent(Metrics::REQUEST_LATENCY_P95_MS, $group);
            if ($p95 === null) {
                continue;
            }
            $history = $window->history(Metrics::REQUEST_LATENCY_P95_MS, $group);
            $severity = $window->severityFor($p95, $limit, $history, self::MIN_MS);
            if ($severity === null) {
                continue;
            }
            $stats = $window->describe($history);
            $findings[] = new Finding(
                rule: $this->key(),
                component: Metrics::COMPONENT_APPLICATION,
                dimension: $group,
                severity: $severity,
                params: ['group' => $group, 'p95' => (int) $p95, 'baseline' => $stats['median'] !== null ? (int) $stats['median'] : null],
                evidence: [
                    DetectionWindow::evidence('p95_ms', (int) $p95),
                    DetectionWindow::evidence('baseline_median_ms', $stats['median']),
                    DetectionWindow::evidence('baseline_samples', $stats['n']),
                    DetectionWindow::evidence('limit_ms', $limit),
                ],
                signals: [Metrics::REQUEST_LATENCY_P95_MS],
                endpoint: $group,
                module: $group,
            );
        }

        return $findings;
    }
}
