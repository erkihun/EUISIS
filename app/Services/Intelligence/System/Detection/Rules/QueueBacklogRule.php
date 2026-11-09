<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Detection\Rules;

use App\Services\Intelligence\System\Detection\DetectionRule;
use App\Services\Intelligence\System\Detection\DetectionWindow;
use App\Services\Intelligence\System\Detection\Finding;
use App\Services\Intelligence\System\Telemetry\Metrics;

/**
 * Pending jobs per queue against the configured backlog limit or the
 * queue's own baseline (at least MIN_PENDING), and the oldest waiting job
 * against the configured age limit. One finding per queue.
 */
final class QueueBacklogRule implements DetectionRule
{
    public const MIN_PENDING = 50;

    public function key(): string
    {
        return 'queue_backlog';
    }

    public function evaluate(DetectionWindow $window): array
    {
        $findings = [];
        $limit = $window->threshold('queue_backlog_jobs');
        $ageLimitMinutes = $window->threshold('queue_oldest_job_minutes');

        foreach ($window->signals->dimensions(Metrics::QUEUE_PENDING, $window->now->subMinutes(15)) as $queue) {
            $pending = $window->recent(Metrics::QUEUE_PENDING, $queue);
            $ageSeconds = $window->recent(Metrics::QUEUE_OLDEST_AGE_SECONDS, $queue);
            if ($pending === null) {
                continue;
            }
            $history = $window->history(Metrics::QUEUE_PENDING, $queue);
            $severity = $window->severityFor($pending, $limit, $history, self::MIN_PENDING);
            if ($ageLimitMinutes !== null && $ageSeconds !== null && $ageSeconds >= $ageLimitMinutes * 60) {
                $ageSeverity = $ageSeconds >= 3 * $ageLimitMinutes * 60 ? 'critical' : 'high';
                $severity = $this->max($severity, $ageSeverity);
            }
            if ($severity === null) {
                continue;
            }
            $stats = $window->describe($history);
            $findings[] = new Finding(
                rule: $this->key(),
                component: Metrics::COMPONENT_QUEUE,
                dimension: $queue,
                severity: $severity,
                params: ['queue' => $queue, 'pending' => (int) $pending, 'age_minutes' => $ageSeconds !== null ? (int) round($ageSeconds / 60) : 0],
                evidence: [
                    DetectionWindow::evidence('pending', (int) $pending),
                    DetectionWindow::evidence('oldest_minutes', $ageSeconds !== null ? round($ageSeconds / 60, 1) : null),
                    DetectionWindow::evidence('baseline_median', $stats['median']),
                    DetectionWindow::evidence('baseline_samples', $stats['n']),
                    DetectionWindow::evidence('limit_jobs', $limit),
                    DetectionWindow::evidence('limit_age_minutes', $ageLimitMinutes),
                ],
                signals: [Metrics::QUEUE_PENDING, Metrics::QUEUE_OLDEST_AGE_SECONDS],
                endpoint: $queue,
                module: 'queue:'.$queue,
            );
        }

        return $findings;
    }

    private function max(?string $a, ?string $b): ?string
    {
        $rank = ['info' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return $rank[$a] >= $rank[$b] ? $a : $b;
    }
}
