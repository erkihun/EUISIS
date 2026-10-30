<?php

declare(strict_types=1);

namespace App\Services\Grievances;

use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;

/**
 * Typed access to System Settings → Grievance Management. Every value here is
 * a policy choice (docs/grievance-management.md §9); code never hard-codes it.
 */
final class GrievanceSettings
{
    private const GROUP = SystemSettingsRegistry::GROUP_GRIEVANCES;

    public function __construct(private readonly SystemSettingsService $settings) {}

    public function enabled(): bool
    {
        return $this->bool('enabled', true);
    }

    public function intakeReviewEnabled(): bool
    {
        return $this->bool('intake_review_enabled', true);
    }

    public function allowRejectionAtIntake(): bool
    {
        return $this->bool('allow_rejection_at_intake', false);
    }

    public function withdrawalRequiresApprovalAfterReview(): bool
    {
        return $this->bool('withdrawal_requires_approval_after_review', true);
    }

    public function committeeMinMembers(): int
    {
        return max(1, $this->int('committee_min_members', 3));
    }

    public function committeeMaxMembers(): int
    {
        return max($this->committeeMinMembers(), $this->int('committee_max_members', 5));
    }

    public function committeeRequiresWriter(): bool
    {
        return $this->bool('committee_require_writer', true);
    }

    /** none | majority | all | fixed_count */
    public function quorumRule(): string
    {
        $rule = (string) $this->settings->get(self::GROUP, 'quorum_rule', 'none');

        return in_array($rule, ['none', 'majority', 'all', 'fixed_count'], true) ? $rule : 'none';
    }

    public function quorumFixedCount(): int
    {
        return max(0, $this->int('quorum_fixed_count', 0));
    }

    public function votingEnabled(): bool
    {
        return $this->bool('voting_enabled', false);
    }

    public function dissentEnabled(): bool
    {
        return $this->bool('dissent_enabled', true);
    }

    public function unitStaffSeeAllCases(): bool
    {
        return $this->bool('unit_staff_see_all_cases', false);
    }

    /** @return list<int> ISO weekdays (1 = Monday … 7 = Sunday) */
    public function workWeekDays(): array
    {
        $days = $this->settings->get(self::GROUP, 'work_week_days', ['1', '2', '3', '4', '5']);
        $days = is_array($days) ? $days : explode(',', (string) $days);
        $days = array_values(array_unique(array_filter(array_map('intval', $days), fn (int $d): bool => $d >= 1 && $d <= 7)));
        sort($days);

        return $days === [] ? [1, 2, 3, 4, 5] : $days;
    }

    public function dueSoonDays(): int
    {
        return max(0, $this->int('due_soon_days', 1));
    }

    public function appealEnabled(): bool
    {
        return $this->bool('appeal_enabled', true);
    }

    public function autoCloseAfterAppealWindow(): bool
    {
        return $this->bool('auto_close_after_appeal_window', false);
    }

    public function allowSelfApproval(): bool
    {
        return $this->bool('allow_self_approval', false);
    }

    public function evidenceMaxSizeKb(): int
    {
        return max(256, $this->int('evidence_max_size_kb', 10240));
    }

    /** @return list<string> */
    public function evidenceAllowedExtensions(): array
    {
        $value = $this->settings->get(self::GROUP, 'evidence_allowed_extensions', ['pdf', 'jpg', 'jpeg', 'png']);
        $value = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_unique(array_filter(array_map(fn ($e): string => strtolower(trim((string) $e)), $value))));
    }

    public function retentionYears(): int
    {
        return max(0, $this->int('retention_years', 0));
    }

    public function reportMinGroupSize(): int
    {
        return max(1, $this->int('report_min_group_size', 5));
    }

    public function smsNoticesEnabled(): bool
    {
        return $this->bool('sms_notices_enabled', false);
    }

    private function bool(string $key, bool $default): bool
    {
        return filter_var($this->settings->get(self::GROUP, $key, $default), FILTER_VALIDATE_BOOLEAN);
    }

    private function int(string $key, int $default): int
    {
        return (int) $this->settings->get(self::GROUP, $key, $default);
    }
}
