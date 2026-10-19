<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Detection\Rules;

use App\Services\Intelligence\System\Detection\DetectionRule;
use App\Services\Intelligence\System\Detection\DetectionWindow;
use App\Services\Intelligence\System\Detection\Finding;
use App\Services\Intelligence\System\Telemetry\Metrics;

/** Redis not answering where Redis is configured. No sample (Redis not used here) means nothing, never an incident. */
final class RedisUnavailableRule implements DetectionRule
{
    public function key(): string
    {
        return 'redis_unavailable';
    }

    public function evaluate(DetectionWindow $window): array
    {
        $available = $window->recent(Metrics::REDIS_AVAILABLE);
        if ($available === null || $available >= 1) {
            return [];
        }

        return [new Finding($this->key(), Metrics::COMPONENT_REDIS, null, 'critical', [], [DetectionWindow::evidence('available', 0)], [Metrics::REDIS_AVAILABLE], module: 'redis')];
    }
}
