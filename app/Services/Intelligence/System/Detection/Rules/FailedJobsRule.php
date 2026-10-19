<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Detection\Rules;

use App\Services\Intelligence\System\Detection\DetectionRule;
use App\Services\Intelligence\System\Detection\DetectionWindow;
use App\Services\Intelligence\System\Detection\Finding;
use App\Services\Intelligence\System\Telemetry\Metrics;

/** Failed jobs in the last hour against the configured hourly limit; the last five-minute count against its baseline (at least MIN_FAILED). */
final class FailedJobsRule implements DetectionRule
{
    public const MIN_FAILED = 3;

    public function key(): string
    {
        return 'failed_jobs';
    }

    public function evaluate(DetectionWindow $window): array
    {
        $recent = $window->recent(Metrics::QUEUE_FAILED_5M);
        if ($recent === null) {
            return [];
        }
        $hourly = $window->recentSum(Metrics::QUEUE_FAILED_5M, null, 60);
        $limit = $window->threshold('failed_jobs_per_hour');
        $history = $window->history(Metrics::QUEUE_FAILED_5M);

        $severity = null;
        if ($limit !== null && $hourly >= $limit) {
            $severity = $hourly >= 2 * $limit ? 'critical' : 'high';
        } elseif ($window->abnormallyHigh($recent, $history, self::MIN_FAILED)) {
            $severity = 'medium';
        }
        if ($severity === null) {
            return [];
        }
        $stats = $window->describe($history);

        return [new Finding(
            rule: $this->key(),
            component: Metrics::COMPONENT_QUEUE,
            dimension: null,
            severity: $severity,
            params: ['hourly' => (int) $hourly, 'recent' => (int) $recent],
            evidence: [
                DetectionWindow::evidence('failed_last_hour', (int) $hourly),
                DetectionWindow::evidence('failed_last_5m', (int) $recent),
                DetectionWindow::evidence('baseline_median_5m', $stats['median']),
                DetectionWindow::evidence('baseline_samples', $stats['n']),
                DetectionWindow::evidence('limit_per_hour', $limit),
            ],
            signals: [Metrics::QUEUE_FAILED_5M],
            module: 'queue',
        )];
    }
}
