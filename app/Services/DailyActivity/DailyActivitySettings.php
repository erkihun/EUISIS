<?php

declare(strict_types=1);

namespace App\Services\DailyActivity;

use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Typed reader over the `daily_activity` settings group, plus the clock the
 * module runs on.
 *
 * config('app.timezone') is UTC, but a working day in Addis Ababa ends at the
 * local deadline, so "today" and "late" are always computed in the
 * localization timezone setting (Africa/Addis_Ababa by default).
 */
class DailyActivitySettings
{
    private const GROUP = SystemSettingsRegistry::GROUP_DAILY_ACTIVITY;

    public function __construct(private readonly SystemSettingsService $settings) {}

    public function enabled(): bool
    {
        return $this->bool('enabled', true);
    }

    public function requireDailySubmission(): bool
    {
        return $this->bool('require_daily_submission', true);
    }

    /** @return array<int, int> ISO weekdays, 1 = Monday … 7 = Sunday */
    public function workWeekDays(): array
    {
        $raw = $this->settings->get(self::GROUP, 'work_week_days', ['1', '2', '3', '4', '5']);
        $days = array_values(array_unique(array_filter(
            array_map('intval', is_array($raw) ? $raw : explode(',', (string) $raw)),
            static fn (int $day): bool => $day >= 1 && $day <= 7,
        )));

        return $days === [] ? [1, 2, 3, 4, 5] : $days;
    }

    public function trackingStartDate(): ?Carbon
    {
        $value = $this->settings->get(self::GROUP, 'tracking_start_date');

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value, $this->timezone())->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    public function submissionDeadline(): string
    {
        return $this->time('submission_deadline', '18:00');
    }

    public function rejectLateSubmission(): bool
    {
        return $this->bool('reject_late_submission', false);
    }

    public function allowBackdatedSubmission(): bool
    {
        return $this->bool('allow_backdated_submission', true);
    }

    public function maxBackdateDays(): int
    {
        return max(0, (int) $this->settings->get(self::GROUP, 'max_backdate_days', 3));
    }

    public function requireLateReason(): bool
    {
        return $this->bool('require_late_reason', true);
    }

    public function requireOutputResult(): bool
    {
        return $this->bool('require_output_result', true);
    }

    public function managerReviewRequired(): bool
    {
        return $this->bool('manager_review_required', true);
    }

    public function notifyOnApproval(): bool
    {
        return $this->bool('notify_on_approval', false);
    }

    public function autoReminderEnabled(): bool
    {
        return $this->bool('auto_reminder_enabled', true);
    }

    public function reminderTime(): string
    {
        return $this->time('reminder_time', '17:00');
    }

    public function evidenceAttachmentsEnabled(): bool
    {
        return $this->bool('evidence_attachments_enabled', true);
    }

    public function maxAttachmentSizeKb(): int
    {
        return max(100, (int) $this->settings->get(self::GROUP, 'max_attachment_size_kb', 5120));
    }

    // ── Clock ────────────────────────────────────────────────────────────────

    public function timezone(): string
    {
        $timezone = (string) $this->settings->get(SystemSettingsRegistry::GROUP_LOCALIZATION, 'timezone', 'Africa/Addis_Ababa');

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'Africa/Addis_Ababa';
    }

    public function now(): Carbon
    {
        return Carbon::now($this->timezone());
    }

    /** Today's local date, as a start-of-day Carbon in the module timezone. */
    public function today(): Carbon
    {
        return $this->now()->startOfDay();
    }

    /** The deadline instant for a given activity date, in the module timezone. */
    public function deadlineFor(Carbon $activityDate): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', $this->submissionDeadline()));

        return Carbon::create($activityDate->year, $activityDate->month, $activityDate->day, $hour, $minute, 0, $this->timezone());
    }

    /** @return array<string, mixed> Values the employee entry page needs. */
    public function forClient(): array
    {
        return [
            'enabled' => $this->enabled(),
            'submission_deadline' => $this->submissionDeadline(),
            'allow_backdated_submission' => $this->allowBackdatedSubmission(),
            'max_backdate_days' => $this->maxBackdateDays(),
            'require_late_reason' => $this->requireLateReason(),
            'require_output_result' => $this->requireOutputResult(),
            'evidence_attachments_enabled' => $this->evidenceAttachmentsEnabled(),
            'max_attachment_size_kb' => $this->maxAttachmentSizeKb(),
        ];
    }

    private function bool(string $key, bool $default): bool
    {
        $value = $this->settings->get(self::GROUP, $key, $default);

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function time(string $key, string $default): string
    {
        $value = (string) $this->settings->get(self::GROUP, $key, $default);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : $default;
    }
}
