<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;

/**
 * Typed reader for the `situation_room` settings group. Attention thresholds
 * are nullable on purpose: null means "not configured", and the Critical
 * Attention view reports CONFIGURATION_REQUIRED instead of using a cut-off
 * nobody decided.
 */
final class SituationSettings
{
    public const GROUP = SystemSettingsRegistry::GROUP_SITUATION_ROOM;

    /** Every attention rule and the setting that holds its threshold. */
    public const THRESHOLDS = [
        'complaint_open_days',
        'tip_off_unassigned_days',
        'daily_review_backlog_days',
        'field_work_overdue_hours',
        'vacancy_rate_percent',
    ];

    public function __construct(private readonly SystemSettingsService $settings) {}

    public function defaultPeriod(): string
    {
        $value = (string) $this->settings->get(self::GROUP, 'default_period', 'month');

        return in_array($value, ['today', 'week', 'month', 'quarter', 'year'], true) ? $value : 'month';
    }

    public function topN(): int
    {
        return $this->bounded('top_n', 10, 5, 50);
    }

    public function cacheSeconds(): int
    {
        return $this->bounded('cache_seconds', 300, 0, 3600);
    }

    public function smallCellThreshold(): int
    {
        return $this->bounded('small_cell_threshold', 5, 1, 20);
    }

    public function wallRefreshSeconds(): int
    {
        return $this->bounded('wall_refresh_seconds', 300, 60, 3600);
    }

    public function wallRotationSeconds(): int
    {
        return $this->bounded('wall_rotation_seconds', 30, 10, 600);
    }

    /** A configured attention threshold, or null when nobody has set one. */
    public function threshold(string $key): ?int
    {
        $value = $this->settings->get(self::GROUP, $key);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function bounded(string $key, int $default, int $min, int $max): int
    {
        $value = $this->settings->get(self::GROUP, $key, $default);

        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }
}
