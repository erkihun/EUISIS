<?php

declare(strict_types=1);

namespace App\Services\Intelligence;

use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;

/**
 * Typed reader for the `intelligence` settings group. Every limit that could
 * be a policy is nullable and OFF when blank: a rule with no configured and
 * no baseline-derived limit counts nothing and says so (brief §112–113).
 * Provider secrets never live here; they come from the environment.
 */
final class IntelligenceSettings
{
    public const AI_PROVIDERS = ['none', 'openai_compatible', 'anthropic'];

    public const DATA_CLASSES = ['aggregates', 'structured_signals', 'redacted_logs', 'knowledge_documents'];

    /** Nullable detection limits: key => [min, max]. */
    public const THRESHOLDS = [
        'http_5xx_rate_percent' => [1, 100],
        'http_p95_latency_ms' => [50, 60000],
        'queue_backlog_jobs' => [1, 1000000],
        'queue_oldest_job_minutes' => [1, 1440],
        'failed_jobs_per_hour' => [1, 100000],
        'exception_repeat_count' => [2, 100000],
        'db_latency_ms' => [1, 60000],
        'cache_latency_ms' => [1, 60000],
    ];

    public function __construct(private readonly SystemSettingsService $settings) {}

    public function aiEnabled(): bool
    {
        return (bool) $this->get('ai_enabled', false) && $this->aiProvider() !== 'none';
    }

    public function aiProvider(): string
    {
        $provider = (string) $this->get('ai_provider', 'none');

        return in_array($provider, self::AI_PROVIDERS, true) ? $provider : 'none';
    }

    public function aiModel(): ?string
    {
        $model = trim((string) $this->get('ai_model', ''));

        return $model === '' ? null : $model;
    }

    public function aiTimeoutSeconds(): int
    {
        return $this->int('ai_timeout_seconds', 20, 5, 120);
    }

    public function aiMaxInputChars(): int
    {
        return $this->int('ai_max_input_chars', 12000, 1000, 200000);
    }

    /** Data classes that may leave the platform towards an AI provider. @return list<string> */
    public function outboundDataClasses(): array
    {
        $raw = $this->get('ai_outbound_data_classes', ['aggregates', 'structured_signals']);
        $list = is_array($raw) ? $raw : array_filter(array_map('trim', explode(',', (string) $raw)));

        return array_values(array_intersect(self::DATA_CLASSES, $list));
    }

    public function knowledgeEnabled(): bool
    {
        return (bool) $this->get('knowledge_enabled', true);
    }

    public function forecastingEnabled(): bool
    {
        return (bool) $this->get('forecasting_enabled', false);
    }

    public function runbookExecutionEnabled(): bool
    {
        return (bool) $this->get('runbook_execution_enabled', false);
    }

    public function telemetryEnabled(): bool
    {
        return (bool) $this->get('telemetry_enabled', true);
    }

    public function cacheSeconds(): int
    {
        return $this->int('cache_seconds', 300, 0, 3600);
    }

    public function baselineWindowDays(): int
    {
        return $this->int('baseline_window_days', 28, 7, 365);
    }

    /** How many robust deviations from the baseline count as abnormal. */
    public function anomalySigma(): int
    {
        return $this->int('anomaly_sigma', 3, 2, 4);
    }

    public function retentionDays(): int
    {
        return $this->int('retention_days', 180, 30, 3650);
    }

    /** `critical`, `high`, `medium` or `none`: the least severity that notifies. */
    public function notifyMinSeverity(): string
    {
        $value = (string) $this->get('notify_min_severity', 'high');

        return in_array($value, ['critical', 'high', 'medium', 'none'], true) ? $value : 'high';
    }

    /** A configured limit, or null when the rule is off. */
    public function threshold(string $key): ?int
    {
        if (! array_key_exists($key, self::THRESHOLDS)) {
            return null;
        }
        $value = $this->get($key, null);

        return $value === null || $value === '' ? null : (int) $value;
    }

    private function int(string $key, int $default, int $min, int $max): int
    {
        return max($min, min($max, (int) ($this->get($key, $default) ?? $default)));
    }

    private function get(string $key, mixed $default): mixed
    {
        return $this->settings->get(SystemSettingsRegistry::GROUP_INTELLIGENCE, $key, $default);
    }
}
