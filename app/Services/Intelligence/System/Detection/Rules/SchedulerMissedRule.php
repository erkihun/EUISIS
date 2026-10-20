<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Detection\Rules;

use App\Services\Intelligence\System\Detection\DetectionRule;
use App\Services\Intelligence\System\Detection\DetectionWindow;
use App\Services\Intelligence\System\Detection\Finding;
use App\Services\Intelligence\System\Telemetry\Metrics;

/** Heartbeats come every five minutes; three missed is a stopped scheduler (high), twelve a long outage (critical). */
final class SchedulerMissedRule implements DetectionRule
{
    public const MISSED_MINUTES = 15;

    public const CRITICAL_MINUTES = 60;

    public function key(): string
    {
        return 'scheduler_missed';
    }

    public function evaluate(DetectionWindow $window): array
    {
        $last = $window->signals->latest(Metrics::SCHEDULER_HEARTBEAT);
        if ($last === null) {
            return [];
        }
        $ageMinutes = (int) round(($window->now->getTimestamp() - $last['bucket_start']->getTimestamp()) / 60);
        if ($ageMinutes < self::MISSED_MINUTES) {
            return [];
        }

        return [new Finding($this->key(), Metrics::COMPONENT_SCHEDULER, null, $ageMinutes >= self::CRITICAL_MINUTES ? 'critical' : 'high', ['minutes' => $ageMinutes],
            [DetectionWindow::evidence('last_heartbeat', $last['bucket_start']->toIso8601String()), DetectionWindow::evidence('age_minutes', $ageMinutes)], [Metrics::SCHEDULER_HEARTBEAT], module: 'scheduler')];
    }
}
