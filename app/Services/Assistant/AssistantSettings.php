<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;

/**
 * Typed reader of System Settings › Assistant. Every capability switch is
 * read here so the resolver, the tools and the UI agree; nothing about the
 * assistant's behaviour is a code constant (docs/assistant/architecture.md).
 */
final class AssistantSettings
{
    public function __construct(private readonly SystemSettingsService $settings) {}

    public function enabled(): bool
    {
        return $this->bool('enabled', true);
    }

    public function knowledgeEnabled(): bool
    {
        return $this->bool('knowledge_enabled', true);
    }

    public function analyticsEnabled(): bool
    {
        return $this->bool('analytics_enabled', true);
    }

    public function diagnosticsEnabled(): bool
    {
        return $this->bool('diagnostics_enabled', true);
    }

    public function supportEnabled(): bool
    {
        return $this->bool('support_enabled', true);
    }

    public function publicEnabled(): bool
    {
        return $this->bool('public_enabled', false);
    }

    public function maxMessageChars(): int
    {
        return max(200, min(8000, $this->int('max_message_chars', 2000)));
    }

    public function retentionDays(): int
    {
        return max(1, $this->int('retention_days', 30));
    }

    public function rateLimitPerMinute(): int
    {
        return max(1, $this->int('rate_limit_per_minute', 20));
    }

    public function dailyMessageCap(): int
    {
        return max(10, $this->int('daily_message_cap', 300));
    }

    public function historyWindow(): int
    {
        return max(0, min(20, $this->int('history_window', 6)));
    }

    private function bool(string $key, bool $default): bool
    {
        return filter_var($this->settings->get(SystemSettingsRegistry::GROUP_ASSISTANT, $key, $default), FILTER_VALIDATE_BOOLEAN);
    }

    private function int(string $key, int $default): int
    {
        $value = $this->settings->get(SystemSettingsRegistry::GROUP_ASSISTANT, $key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
