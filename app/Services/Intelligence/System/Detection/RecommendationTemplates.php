<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Detection;

/**
 * Deterministic recommendations per rule (brief §48, §104): immediate
 * mitigation, short-term fix, permanent fix and verification, each classed
 * as a safe check, a potential fix or a high-risk change. Texts are
 * dictionary keys (`intelligence.recommendations.<rule>.<category>`), so the
 * stored row reads in either language and a high-risk change is always
 * labelled as one that a person executes outside any automation.
 */
final class RecommendationTemplates
{
    /** @var array<string, array<string, string>> rule => category => risk */
    private const TEMPLATES = [
        'http_error_rate' => ['immediate' => 'safe_check', 'short_term' => 'potential_fix', 'permanent' => 'high_risk', 'verification' => 'safe_check'],
        'http_latency' => ['immediate' => 'safe_check', 'short_term' => 'potential_fix', 'permanent' => 'high_risk', 'verification' => 'safe_check'],
        'queue_backlog' => ['immediate' => 'safe_check', 'short_term' => 'potential_fix', 'permanent' => 'potential_fix', 'verification' => 'safe_check'],
        'failed_jobs' => ['immediate' => 'safe_check', 'short_term' => 'potential_fix', 'verification' => 'safe_check'],
        'error_repeat' => ['immediate' => 'safe_check', 'short_term' => 'potential_fix', 'permanent' => 'potential_fix', 'verification' => 'safe_check'],
        'db_latency' => ['immediate' => 'safe_check', 'short_term' => 'potential_fix', 'permanent' => 'high_risk', 'verification' => 'safe_check'],
        'db_connections' => ['immediate' => 'safe_check', 'short_term' => 'potential_fix', 'permanent' => 'high_risk', 'verification' => 'safe_check'],
        'cache_unavailable' => ['immediate' => 'safe_check', 'short_term' => 'potential_fix', 'verification' => 'safe_check'],
        'redis_unavailable' => ['immediate' => 'safe_check', 'short_term' => 'potential_fix', 'verification' => 'safe_check'],
        'scheduler_missed' => ['immediate' => 'safe_check', 'short_term' => 'potential_fix', 'verification' => 'safe_check'],
        'storage' => ['immediate' => 'safe_check', 'short_term' => 'high_risk', 'verification' => 'safe_check'],
        'backup' => ['immediate' => 'safe_check', 'short_term' => 'potential_fix', 'verification' => 'safe_check'],
        'integration_failure' => ['immediate' => 'safe_check', 'short_term' => 'potential_fix', 'verification' => 'safe_check'],
        'event_integration' => ['immediate' => 'safe_check', 'short_term' => 'potential_fix', 'verification' => 'safe_check'],
        'auth_failures' => ['immediate' => 'safe_check', 'short_term' => 'potential_fix', 'verification' => 'safe_check'],
    ];

    /** @return list<array{category: string, risk: string, title: string, body: string}> */
    public static function for(string $rule): array
    {
        $out = [];
        foreach (self::TEMPLATES[$rule] ?? [] as $category => $risk) {
            $out[] = [
                'category' => $category,
                'risk' => $risk,
                'title' => "intelligence.recommendations.{$rule}.{$category}.title",
                'body' => "intelligence.recommendations.{$rule}.{$category}.body",
            ];
        }

        return $out;
    }

    /** @return list<string> */
    public static function rules(): array
    {
        return array_keys(self::TEMPLATES);
    }
}
