<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Detection\Rules;

use App\Services\Intelligence\System\Detection\DetectionRule;
use App\Services\Intelligence\System\Detection\DetectionWindow;
use App\Services\Intelligence\System\Detection\Finding;
use App\Services\Intelligence\System\Telemetry\Metrics;

/**
 * Event integration layer health (docs/integration/transactional-outbox.md).
 *
 *   outbox      the oldest unpublished event against the configured limit
 *               (integration_outbox_oldest_minutes). No limit configured →
 *               no finding: no SLA is invented.
 *   dead        dead webhook deliveries at or above the configured limit
 *               (integration_dead_deliveries).
 *   <partner>   webhook attempt failures for one application that are
 *               abnormally high against its own baseline, with at least
 *               MIN_FAILURES in the window — one transient failure never
 *               marks a partner down.
 *
 * Incident de-duplication and cooldown come from the incident manager
 * (one open incident per rule + dimension).
 */
final class EventIntegrationRule implements DetectionRule
{
    public const WINDOW_MINUTES = 60;

    public const MIN_FAILURES = 5;

    public function key(): string
    {
        return 'event_integration';
    }

    public function evaluate(DetectionWindow $window): array
    {
        $findings = [];

        $ageLimit = $window->threshold('integration_outbox_oldest_minutes');
        $oldest = $window->recent(Metrics::INTEGRATION_OUTBOX_OLDEST_SECONDS, null);
        if ($ageLimit !== null && $oldest !== null && $oldest >= $ageLimit * 60) {
            $minutes = (int) round($oldest / 60);
            $findings[] = $this->finding('outbox', $oldest >= 3 * $ageLimit * 60 ? 'critical' : 'high', ['subject' => 'outbox', 'value' => $minutes, 'limit' => $ageLimit],
                [DetectionWindow::evidence('oldest_pending_minutes', $minutes), DetectionWindow::evidence('limit_minutes', $ageLimit), DetectionWindow::evidence('pending', $window->recent(Metrics::INTEGRATION_OUTBOX_PENDING, null))],
                [Metrics::INTEGRATION_OUTBOX_OLDEST_SECONDS, Metrics::INTEGRATION_OUTBOX_PENDING]);
        }

        $deadLimit = $window->threshold('integration_dead_deliveries');
        $dead = $window->recent(Metrics::INTEGRATION_WEBHOOK_DEAD_TOTAL, null);
        if ($deadLimit !== null && $dead !== null && $dead >= $deadLimit) {
            $findings[] = $this->finding('dead', $dead >= 3 * $deadLimit ? 'high' : 'medium', ['subject' => 'dead', 'value' => (int) $dead, 'limit' => $deadLimit],
                [DetectionWindow::evidence('dead_deliveries', (int) $dead), DetectionWindow::evidence('limit', $deadLimit)],
                [Metrics::INTEGRATION_WEBHOOK_DEAD_TOTAL]);
        }

        foreach ($window->signals->dimensions(Metrics::INTEGRATION_WEBHOOK_FAILURES, $window->now->subMinutes(self::WINDOW_MINUTES)) as $partner) {
            $failures = $window->recentSum(Metrics::INTEGRATION_WEBHOOK_FAILURES, $partner, self::WINDOW_MINUTES);
            if ($failures < self::MIN_FAILURES || ! $window->abnormallyHigh($failures, $window->history(Metrics::INTEGRATION_WEBHOOK_FAILURES, $partner, self::WINDOW_MINUTES), self::MIN_FAILURES)) {
                continue;
            }
            $findings[] = $this->finding((string) $partner, 'medium', ['subject' => (string) $partner, 'value' => (int) $failures, 'limit' => self::MIN_FAILURES],
                [DetectionWindow::evidence('partner', (string) $partner), DetectionWindow::evidence('failed_attempts', (int) $failures), DetectionWindow::evidence('window', self::WINDOW_MINUTES.' min')],
                [Metrics::INTEGRATION_WEBHOOK_FAILURES]);
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  list<array{label: string, value: string|int|float|null}>  $evidence
     * @param  list<string>  $signals
     */
    private function finding(string $dimension, string $severity, array $params, array $evidence, array $signals): Finding
    {
        return new Finding($this->key(), Metrics::COMPONENT_INTEGRATIONS, $dimension, $severity, $params, $evidence, $signals,
            endpoint: 'integrations:'.$dimension, module: 'event_integration:'.$dimension);
    }
}
