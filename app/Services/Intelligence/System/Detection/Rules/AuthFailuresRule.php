<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Detection\Rules;

use App\Services\Intelligence\System\Detection\DetectionRule;
use App\Services\Intelligence\System\Detection\DetectionWindow;
use App\Services\Intelligence\System\Detection\Finding;
use App\Services\Intelligence\System\Telemetry\Metrics;

/**
 * Security signal (brief §108–109): denied (401/403), throttled (429) and
 * failed MFA challenges in the last fifteen minutes, abnormal against their
 * own baseline and at least MIN_COUNT. It names no user and accuses nobody;
 * formal security response stays with security personnel.
 */
final class AuthFailuresRule implements DetectionRule
{
    public const WINDOW_MINUTES = 15;

    public const MIN_COUNT = 20;

    public function key(): string
    {
        return 'auth_failures';
    }

    public function evaluate(DetectionWindow $window): array
    {
        $denied = $window->recentSum(Metrics::REQUEST_401, null, self::WINDOW_MINUTES) + $window->recentSum(Metrics::REQUEST_403, null, self::WINDOW_MINUTES);
        $throttled = $window->recentSum(Metrics::REQUEST_429, null, self::WINDOW_MINUTES);
        $mfa = $window->recentSum(Metrics::AUTH_MFA_FAILURES, null, self::WINDOW_MINUTES);
        $total = $denied + $throttled + $mfa;
        if ($total < self::MIN_COUNT) {
            return [];
        }

        // Request counters carry a route-group dimension; the baseline is the sum over all groups per bucket.
        $history = $this->summedHistory($window);
        $recent = $this->recentBucketTotal($window);
        if (! $window->abnormallyHigh($recent, $history, 5)) {
            return [];
        }

        return [new Finding($this->key(), Metrics::COMPONENT_SECURITY, null, 'medium', ['count' => (int) $total, 'minutes' => self::WINDOW_MINUTES],
            [DetectionWindow::evidence('denied_401_403', (int) $denied), DetectionWindow::evidence('throttled_429', (int) $throttled), DetectionWindow::evidence('mfa_failures', (int) $mfa), DetectionWindow::evidence('window', self::WINDOW_MINUTES.' min')],
            [Metrics::REQUEST_401, Metrics::REQUEST_403, Metrics::REQUEST_429, Metrics::AUTH_MFA_FAILURES], module: 'security')];
    }

    /** @return list<float> */
    private function summedHistory(DetectionWindow $window): array
    {
        $byBucket = [];
        foreach ([Metrics::REQUEST_401, Metrics::REQUEST_403, Metrics::REQUEST_429] as $metric) {
            foreach ($window->signals->dimensions($metric, $window->baselineSince) as $group) {
                foreach ($window->signals->series($metric, $group, $window->baselineSince, $window->now->subMinutes(30)) as $sample) {
                    $byBucket[$sample['bucket_start']] = ($byBucket[$sample['bucket_start']] ?? 0) + $sample['value'];
                }
            }
        }
        foreach ($window->signals->series(Metrics::AUTH_MFA_FAILURES, null, $window->baselineSince, $window->now->subMinutes(30)) as $sample) {
            $byBucket[$sample['bucket_start']] = ($byBucket[$sample['bucket_start']] ?? 0) + $sample['value'];
        }
        ksort($byBucket);

        return array_values(array_map(fn ($v): float => (float) $v, $byBucket));
    }

    private function recentBucketTotal(DetectionWindow $window): float
    {
        $total = 0.0;
        foreach ([Metrics::REQUEST_401, Metrics::REQUEST_403, Metrics::REQUEST_429] as $metric) {
            foreach ($window->signals->sumByDimension($metric, $window->now->subMinutes(10)) as $value) {
                $total += $value;
            }
        }

        return $total + $window->recentSum(Metrics::AUTH_MFA_FAILURES, null, 10);
    }
}
