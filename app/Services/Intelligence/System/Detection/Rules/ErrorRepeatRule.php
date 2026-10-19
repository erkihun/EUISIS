<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Detection\Rules;

use App\Models\IntelligenceErrorFingerprint;
use App\Services\Intelligence\System\Detection\DetectionRule;
use App\Services\Intelligence\System\Detection\DetectionWindow;
use App\Services\Intelligence\System\Detection\Finding;
use App\Services\Intelligence\System\Telemetry\Metrics;

/**
 * The same error signature repeating (brief §30, §34): a fingerprint seen in
 * the last fifteen minutes whose occurrences reach the configured limit
 * (high; twice the limit: critical), or — without a limit — MIN_RECENT
 * occurrences within the last hour of its first sighting (medium). One
 * incident per signature.
 */
final class ErrorRepeatRule implements DetectionRule
{
    public const MIN_RECENT = 10;

    public const RECENT_MINUTES = 15;

    public function key(): string
    {
        return 'error_repeat';
    }

    public function evaluate(DetectionWindow $window): array
    {
        $limit = $window->threshold('exception_repeat_count');
        $findings = [];
        $recent = IntelligenceErrorFingerprint::query()
            ->whereNull('resolved_at')
            ->where('last_seen_at', '>=', $window->now->subMinutes(self::RECENT_MINUTES)->toDateTimeString())
            ->orderByDesc('occurrences')
            ->limit(50)
            ->get();

        foreach ($recent as $fingerprint) {
            $occurrences = (int) $fingerprint->occurrences;
            $severity = null;
            if ($limit !== null && $occurrences >= $limit) {
                $severity = $occurrences >= 2 * $limit ? 'critical' : 'high';
            } elseif ($occurrences >= self::MIN_RECENT && $fingerprint->first_seen_at->gte($window->now->subHour())) {
                $severity = 'medium';
            }
            if ($severity === null) {
                continue;
            }
            $findings[] = new Finding(
                rule: $this->key(),
                component: $fingerprint->component ?? Metrics::COMPONENT_APPLICATION,
                dimension: $fingerprint->fingerprint,
                severity: $severity,
                params: ['exception' => class_basename($fingerprint->exception_class), 'group' => $fingerprint->route_group, 'occurrences' => $occurrences],
                evidence: [
                    DetectionWindow::evidence('exception', $fingerprint->exception_class),
                    DetectionWindow::evidence('route_group', $fingerprint->route_group),
                    DetectionWindow::evidence('route_name', $fingerprint->route_name),
                    DetectionWindow::evidence('occurrences', $occurrences),
                    DetectionWindow::evidence('first_seen', $fingerprint->first_seen_at->toIso8601String()),
                    DetectionWindow::evidence('last_seen', $fingerprint->last_seen_at->toIso8601String()),
                    DetectionWindow::evidence('last_error_id', $fingerprint->last_error_id),
                    DetectionWindow::evidence('limit_count', $limit),
                ],
                signals: ['error_fingerprints'],
                endpoint: $fingerprint->route_group,
                module: $fingerprint->component,
            );
        }

        return $findings;
    }
}
