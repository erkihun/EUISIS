<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Detection\Rules;

use App\Services\Intelligence\System\Detection\DetectionRule;
use App\Services\Intelligence\System\Detection\DetectionWindow;
use App\Services\Intelligence\System\Detection\Finding;
use App\Services\Intelligence\System\Telemetry\Metrics;

/**
 * Per integration provider over the last hour: nothing succeeding at all is
 * a fact (high); failures above the provider's own baseline are medium.
 * Needs MIN_CALLS calls so an idle integration is never judged.
 */
final class IntegrationFailureRule implements DetectionRule
{
    public const WINDOW_MINUTES = 60;

    public const MIN_CALLS = 5;

    public function key(): string
    {
        return 'integration_failure';
    }

    public function evaluate(DetectionWindow $window): array
    {
        $findings = [];
        foreach ($window->signals->dimensions(Metrics::INTEGRATION_CALLS, $window->now->subMinutes(self::WINDOW_MINUTES)) as $provider) {
            $calls = $window->recentSum(Metrics::INTEGRATION_CALLS, $provider, self::WINDOW_MINUTES);
            $failures = $window->recentSum(Metrics::INTEGRATION_FAILURES, $provider, self::WINDOW_MINUTES);
            if ($calls < self::MIN_CALLS || $failures <= 0) {
                continue;
            }
            $severity = null;
            if ($failures >= $calls) {
                $severity = 'high';
            } elseif ($window->abnormallyHigh($failures, $window->history(Metrics::INTEGRATION_FAILURES, $provider, self::WINDOW_MINUTES), 3)) {
                $severity = 'medium';
            }
            if ($severity === null) {
                continue;
            }
            $findings[] = new Finding($this->key(), Metrics::COMPONENT_INTEGRATIONS, $provider, $severity,
                ['provider' => $provider, 'calls' => (int) $calls, 'failures' => (int) $failures, 'minutes' => self::WINDOW_MINUTES],
                [DetectionWindow::evidence('provider', $provider), DetectionWindow::evidence('calls', (int) $calls), DetectionWindow::evidence('failures', (int) $failures), DetectionWindow::evidence('window', self::WINDOW_MINUTES.' min')],
                [Metrics::INTEGRATION_CALLS, Metrics::INTEGRATION_FAILURES], endpoint: $provider, module: 'integration:'.$provider);
        }

        return $findings;
    }
}
