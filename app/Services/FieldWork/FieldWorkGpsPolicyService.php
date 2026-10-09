<?php

declare(strict_types=1);

namespace App\Services\FieldWork;

use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;

/** Resolves auditable, administrator-controlled GPS policy without hidden defaults. */
final class FieldWorkGpsPolicyService
{
    public function __construct(private readonly SystemSettingsService $settings) {}

    /** @return array<string, bool|int|string|null> */
    public function snapshot(): array
    {
        $accuracy = $this->settings->get(SystemSettingsRegistry::GROUP_FIELD_WORK_GPS, 'max_accuracy_meters');

        return [
            'version' => 1,
            'require_check_in' => (bool) $this->settings->get(SystemSettingsRegistry::GROUP_FIELD_WORK_GPS, 'require_check_in'),
            'require_check_out' => (bool) $this->settings->get(SystemSettingsRegistry::GROUP_FIELD_WORK_GPS, 'require_check_out'),
            'max_accuracy_meters' => is_int($accuracy) && $accuracy > 0 ? $accuracy : null,
            'low_accuracy_action' => $this->action('low_accuracy_action'),
            'outside_geofence_action' => $this->action('outside_geofence_action'),
            'location_retention_days' => $this->settings->get(SystemSettingsRegistry::GROUP_FIELD_WORK_GPS, 'location_retention_days'),
            'offline_capture_policy' => (string) $this->settings->get(SystemSettingsRegistry::GROUP_FIELD_WORK_GPS, 'offline_capture_policy'),
            'team_capture_policy' => (string) $this->settings->get(SystemSettingsRegistry::GROUP_FIELD_WORK_GPS, 'team_capture_policy'),
        ];
    }

    public function requiresCheckIn(): bool
    {
        return $this->snapshot()['require_check_in'] === true;
    }

    public function requiresCheckOut(): bool
    {
        return $this->snapshot()['require_check_out'] === true;
    }

    /** @param array<string, bool|int|string|null> $policy @return array{flags:list<string>,review_state:string,blocked:bool,reason:?string} */
    public function evaluate(array $policy, ?float $accuracy, bool $outsideGeofence): array
    {
        $flags = [];
        $actions = [];
        if (is_int($policy['max_accuracy_meters']) && $accuracy !== null && $accuracy > $policy['max_accuracy_meters']) {
            $flags[] = 'low_accuracy';
            $actions[] = $policy['low_accuracy_action'];
        }
        if ($outsideGeofence) {
            $flags[] = 'outside_geofence';
            $actions[] = $policy['outside_geofence_action'];
        }
        $blocked = in_array('block', $actions, true);
        $review = $blocked || in_array('require_review', $actions, true);

        return [
            'flags' => $flags,
            'review_state' => $blocked ? 'blocked' : ($review ? 'pending_supervisor_review' : 'not_required'),
            'blocked' => $blocked,
            'reason' => $blocked ? 'The GPS observation was recorded but cannot satisfy the configured field-work policy.' : null,
        ];
    }

    private function action(string $key): string
    {
        $value = $this->settings->get(SystemSettingsRegistry::GROUP_FIELD_WORK_GPS, $key);

        return in_array($value, ['record_only', 'require_review', 'block'], true) ? $value : 'not_configured';
    }
}
