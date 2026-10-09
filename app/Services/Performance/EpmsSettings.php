<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;

/**
 * Typed access to System Settings → Performance Management. Policy lives in
 * settings so it can change without code (docs/epms-calculation-rules.md §7).
 */
final class EpmsSettings
{
    private const GROUP = SystemSettingsRegistry::GROUP_PERFORMANCE;

    public function __construct(private readonly SystemSettingsService $settings) {}

    public function enabled(): bool
    {
        return $this->bool('enabled', true);
    }

    /** @return array{results: int, competency: int} always totalling 100 */
    public function componentWeights(): array
    {
        $results = max(0, min(100, $this->int('results_weight', 80)));

        return ['results' => $results, 'competency' => 100 - $results];
    }

    public function defaultAchievementCap(): int
    {
        return max(100, min(200, $this->int('default_achievement_cap', 120)));
    }

    public function requireEmployeeAcknowledgement(): bool
    {
        return $this->bool('require_employee_acknowledgement', true);
    }

    public function requireMidyearReview(): bool
    {
        return $this->bool('require_midyear_review', true);
    }

    public function requireYearendSelfAssessment(): bool
    {
        return $this->bool('require_yearend_self_assessment', true);
    }

    public function requireCalibration(): bool
    {
        return $this->bool('require_calibration', false);
    }

    public function requireResultRelease(): bool
    {
        return $this->bool('require_result_release', true);
    }

    public function allowManualActual(): bool
    {
        return $this->bool('allow_manual_kpi_actual', true);
    }

    public function allowScoreAdjustment(): bool
    {
        return $this->bool('allow_score_adjustment', false);
    }

    public function appealWindowDays(): int
    {
        return max(0, $this->int('appeal_window_days', 15));
    }

    public function checkinFrequency(): string
    {
        return (string) $this->settings->get(self::GROUP, 'checkin_frequency', 'monthly');
    }

    public function pipThreshold(): int
    {
        return $this->int('pip_threshold', 60);
    }

    public function atRiskThreshold(): int
    {
        return $this->int('at_risk_threshold', 80);
    }

    public function offTrackThreshold(): int
    {
        return $this->int('off_track_threshold', 60);
    }

    public function amendmentRequiresApproval(): bool
    {
        return $this->bool('amendment_requires_approval', true);
    }

    public function allowSelfApproval(): bool
    {
        return $this->bool('allow_self_approval', false);
    }

    public function prorateTransferResults(): bool
    {
        return $this->bool('prorate_transfer_results', true);
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
