<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Detection\Rules;

use App\Services\Intelligence\System\Detection\DetectionRule;
use App\Services\Intelligence\System\Detection\DetectionWindow;
use App\Services\Intelligence\System\Detection\Finding;
use App\Services\Intelligence\System\Telemetry\Metrics;

/** The backup module's own verdict (BackupStatusService): WARNING is medium, CRITICAL is high; unknown is never an incident. */
final class BackupRule implements DetectionRule
{
    public function key(): string
    {
        return 'backup';
    }

    public function evaluate(DetectionWindow $window): array
    {
        $state = $window->recent(Metrics::BACKUP_HEALTH, null, 30);
        if ($state === null || $state < Metrics::HEALTH_VALUES['degraded'] || $state >= Metrics::HEALTH_VALUES['unknown']) {
            return [];
        }
        $age = $window->recent(Metrics::BACKUP_AGE_HOURS, null, 30);
        $unhealthy = (int) $state === Metrics::HEALTH_VALUES['unhealthy'];

        return [new Finding($this->key(), Metrics::COMPONENT_BACKUPS, null, $unhealthy ? 'high' : 'medium', ['age_hours' => $age !== null ? round($age, 1) : null],
            [DetectionWindow::evidence('backup_state', $unhealthy ? 'unhealthy' : 'degraded'), DetectionWindow::evidence('backup_age_hours', $age)], [Metrics::BACKUP_HEALTH, Metrics::BACKUP_AGE_HOURS], module: 'backups')];
    }
}
