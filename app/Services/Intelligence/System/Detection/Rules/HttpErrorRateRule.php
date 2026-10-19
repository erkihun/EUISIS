<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Detection\Rules;

use App\Services\Intelligence\System\Detection\DetectionRule;
use App\Services\Intelligence\System\Detection\DetectionWindow;
use App\Services\Intelligence\System\Detection\Finding;
use App\Services\Intelligence\System\Telemetry\Metrics;

/**
 * Server-error share per route group over the last fifteen minutes against
 * the configured limit (System Settings › Intelligence) or the robust
 * baseline of the group's own error rate. Needs at least MIN_REQUESTS
 * requests and MIN_FAILURES failures so a single 500 on a quiet route is
 * never an incident.
 */
final class HttpErrorRateRule implements DetectionRule
{
    public const WINDOW_MINUTES = 15;

    public const MIN_REQUESTS = 20;

    public const MIN_FAILURES = 5;

    public function key(): string
    {
        return 'http_error_rate';
    }

    public function evaluate(DetectionWindow $window): array
    {
        $findings = [];
        $limit = $window->threshold('http_5xx_rate_percent');

        foreach ($window->signals->dimensions(Metrics::REQUEST_TOTAL, $window->now->subMinutes(self::WINDOW_MINUTES)) as $group) {
            $requests = $window->recentSum(Metrics::REQUEST_TOTAL, $group, self::WINDOW_MINUTES);
            $failures = $window->recentSum(Metrics::REQUEST_5XX, $group, self::WINDOW_MINUTES);
            if ($requests < self::MIN_REQUESTS || $failures < self::MIN_FAILURES) {
                continue;
            }
            $rate = round(100 * $failures / $requests, 2);
            $history = $window->history(Metrics::REQUEST_ERROR_RATE, $group);
            $severity = $window->severityFor($rate, $limit, $history, 2);
            if ($severity === null) {
                continue;
            }
            $stats = $window->describe($history);
            $findings[] = new Finding(
                rule: $this->key(),
                component: Metrics::COMPONENT_APPLICATION,
                dimension: $group,
                severity: $severity,
                params: ['group' => $group, 'rate' => $rate, 'requests' => (int) $requests, 'failures' => (int) $failures, 'minutes' => self::WINDOW_MINUTES],
                evidence: [
                    DetectionWindow::evidence('window', self::WINDOW_MINUTES.' min'),
                    DetectionWindow::evidence('requests', (int) $requests),
                    DetectionWindow::evidence('failures', (int) $failures),
                    DetectionWindow::evidence('rate_percent', $rate),
                    DetectionWindow::evidence('baseline_median_percent', $stats['median']),
                    DetectionWindow::evidence('baseline_samples', $stats['n']),
                    DetectionWindow::evidence('limit_percent', $limit),
                ],
                signals: [Metrics::REQUEST_TOTAL, Metrics::REQUEST_5XX, Metrics::REQUEST_ERROR_RATE],
                endpoint: $group,
                module: $group,
            );
        }

        return $findings;
    }
}
