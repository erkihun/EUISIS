<?php

declare(strict_types=1);

namespace App\Services\PublicAssistant;

use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;

/**
 * Typed reader of the public-help switches in System Settings › Assistant
 * (docs/public-assistant/architecture.md). The public assistant exists only
 * while the public site itself is on.
 */
final class PublicAssistantSettings
{
    public function __construct(private readonly SystemSettingsService $settings) {}

    public function enabled(): bool
    {
        return $this->bool(SystemSettingsRegistry::GROUP_PUBLIC_SITE, 'enabled', true) && $this->bool(SystemSettingsRegistry::GROUP_ASSISTANT, 'public_enabled', true);
    }

    public function knowledgeEnabled(): bool
    {
        return $this->bool(SystemSettingsRegistry::GROUP_ASSISTANT, 'public_knowledge_enabled', true);
    }

    public function serviceInfoEnabled(): bool
    {
        return $this->bool(SystemSettingsRegistry::GROUP_ASSISTANT, 'public_service_info_enabled', true);
    }

    public function supportEnabled(): bool
    {
        return $this->bool(SystemSettingsRegistry::GROUP_ASSISTANT, 'public_support_enabled', true);
    }

    public function statusEnabled(): bool
    {
        return $this->bool(SystemSettingsRegistry::GROUP_ASSISTANT, 'public_status_enabled', false);
    }

    public function maxMessageChars(): int
    {
        return max(100, min(4000, $this->int('public_max_message_chars', 1000)));
    }

    public function rateLimitPerMinute(): int
    {
        return max(1, min(60, $this->int('public_rate_limit_per_minute', 10)));
    }

    public function dailyCap(): int
    {
        return max(10, $this->int('public_daily_cap', 100));
    }

    public function historyTurns(): int
    {
        return max(0, min(12, $this->int('public_history_turns', 6)));
    }

    private function bool(string $group, string $key, bool $default): bool
    {
        return filter_var($this->settings->get($group, $key, $default), FILTER_VALIDATE_BOOLEAN);
    }

    private function int(string $key, int $default): int
    {
        $value = $this->settings->get(SystemSettingsRegistry::GROUP_ASSISTANT, $key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
