<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Detection\Rules;

use App\Services\Intelligence\System\Detection\DetectionRule;
use App\Services\Intelligence\System\Detection\DetectionWindow;
use App\Services\Intelligence\System\Detection\Finding;
use App\Services\Intelligence\System\Telemetry\Metrics;

/**
 * Connection saturation. The two bounds are facts about a connection pool,
 * not a service objective: at HIGH_PERCENT of max_connections new requests
 * start to wait, at CRITICAL_PERCENT they fail (docs/intelligence-system-health.md).
 */
final class DbConnectionsRule implements DetectionRule
{
    public const HIGH_PERCENT = 90;

    public const CRITICAL_PERCENT = 97;

    public function key(): string
    {
        return 'db_connections';
    }

    public function evaluate(DetectionWindow $window): array
    {
        $percent = $window->recent(Metrics::DB_CONNECTIONS_PERCENT);
        if ($percent === null || $percent < self::HIGH_PERCENT) {
            return [];
        }

        return [new Finding(
            rule: $this->key(),
            component: Metrics::COMPONENT_DATABASE,
            dimension: null,
            severity: $percent >= self::CRITICAL_PERCENT ? 'critical' : 'high',
            params: ['percent' => round($percent, 1), 'active' => (int) ($window->recent(Metrics::DB_CONNECTIONS_ACTIVE) ?? 0), 'max' => (int) ($window->recent(Metrics::DB_CONNECTIONS_MAX) ?? 0)],
            evidence: [
                DetectionWindow::evidence('connections_percent', round($percent, 1)),
                DetectionWindow::evidence('connections_active', $window->recent(Metrics::DB_CONNECTIONS_ACTIVE)),
                DetectionWindow::evidence('connections_max', $window->recent(Metrics::DB_CONNECTIONS_MAX)),
                DetectionWindow::evidence('waiting_locks', $window->recent(Metrics::DB_WAITING_LOCKS)),
            ],
            signals: [Metrics::DB_CONNECTIONS_PERCENT, Metrics::DB_CONNECTIONS_ACTIVE, Metrics::DB_CONNECTIONS_MAX],
            module: 'database',
        )];
    }
}
