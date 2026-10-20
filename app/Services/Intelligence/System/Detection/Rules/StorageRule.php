<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Detection\Rules;

use App\Services\Intelligence\System\Detection\DetectionRule;
use App\Services\Intelligence\System\Detection\DetectionWindow;
use App\Services\Intelligence\System\Detection\Finding;
use App\Services\Intelligence\System\Telemetry\Metrics;

/**
 * Storage facts: a disk that cannot be written to (critical); free space
 * below LOW_PERCENT puts uploads and logs at risk (high), below
 * CRITICAL_PERCENT writes fail soon (critical). These are properties of a
 * disk, not a service objective (docs/intelligence-system-health.md).
 */
final class StorageRule implements DetectionRule
{
    public const LOW_PERCENT = 10;

    public const CRITICAL_PERCENT = 5;

    public function key(): string
    {
        return 'storage';
    }

    public function evaluate(DetectionWindow $window): array
    {
        $writable = $window->recent(Metrics::STORAGE_WRITABLE);
        $free = $window->recent(Metrics::STORAGE_FREE_PERCENT);

        if ($writable !== null && $writable < 1) {
            return [new Finding($this->key(), Metrics::COMPONENT_STORAGE, null, 'critical', ['free' => $free !== null ? round($free, 1) : null],
                [DetectionWindow::evidence('writable', 0), DetectionWindow::evidence('free_percent', $free)], [Metrics::STORAGE_WRITABLE, Metrics::STORAGE_FREE_PERCENT], module: 'storage')];
        }
        if ($free !== null && $free <= self::LOW_PERCENT) {
            return [new Finding($this->key(), Metrics::COMPONENT_STORAGE, null, $free <= self::CRITICAL_PERCENT ? 'critical' : 'high', ['free' => round($free, 1)],
                [DetectionWindow::evidence('free_percent', round($free, 1)), DetectionWindow::evidence('low_percent', self::LOW_PERCENT)], [Metrics::STORAGE_FREE_PERCENT], module: 'storage')];
        }

        return [];
    }
}
