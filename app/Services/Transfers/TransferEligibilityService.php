<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\TransferAnnouncement;
use App\Models\TransferAnnouncementPosition;
use App\Models\TransferSetting;

/**
 * Evaluates only configured, deterministic transfer rules.  It deliberately
 * has no expression evaluator: document review, qualifications, scoring, and
 * discretionary exceptions remain human/policy decisions.
 */
readonly class TransferEligibilityService
{
    public function evaluate(
        Employee $employee,
        TransferAnnouncement $announcement,
        ?TransferSetting $settings = null,
        ?TransferAnnouncementPosition $announcementPosition = null,
    ): array {
        $settings ??= TransferSetting::current();
        $assignment = $employee->currentAssignment;
        $sourcePosition = $assignment?->position;
        $destination = $announcementPosition?->position ?? $announcement->position;
        $destinationOrganizationId = $announcementPosition?->organization_id ?? $announcement->organization_id;
        $destinationGrade = $announcementPosition?->grade_level ?? $announcement->grade_level ?? $destination?->grade_level;
        $rules = [];

        $rules['active_employee'] = [
            'passed' => $employee->status === EmployeeStatus::Active,
            'reason_code' => 'employee_not_active',
        ];
        $rules['active_assignment'] = [
            'passed' => $assignment !== null && $assignment->is_current,
            'reason_code' => 'no_current_assignment',
        ];
        $rules['same_organization'] = [
            'passed' => $settings->allow_cross_institution || $assignment?->organization_id !== $destinationOrganizationId,
            'reason_code' => 'same_organization_not_allowed',
        ];
        $rules['same_position'] = [
            'passed' => ! $settings->require_same_position || $assignment?->position_id === $destination?->id,
            'reason_code' => 'position_mismatch',
        ];
        $rules['same_grade'] = [
            'passed' => ! $settings->require_same_grade
                || ($sourcePosition?->grade_level !== null && $sourcePosition->grade_level === $destinationGrade),
            'reason_code' => 'grade_mismatch',
        ];
        $rules['minimum_service'] = [
            'passed' => $settings->minimum_service_months <= 0
                || ($assignment?->effective_from !== null && $assignment->effective_from->diffInMonths(now()) >= $settings->minimum_service_months),
            'reason_code' => 'minimum_service_not_met',
        ];

        foreach ((array) $announcement->eligibility_rules as $index => $rule) {
            if (! is_array($rule) || ! isset($rule['type'], $rule['operator'], $rule['value'])) {
                $rules['announcement_rule_'.$index] = ['passed' => false, 'reason_code' => 'invalid_announcement_rule'];

                continue;
            }
            $actual = match ($rule['type']) {
                'employment_status' => $employee->status->value,
                'current_grade' => $sourcePosition?->grade_level,
                'current_organization' => $assignment?->organization_id,
                'current_position' => $assignment?->position_id,
                'minimum_service_months' => $assignment?->effective_from?->diffInMonths(now()),
                default => null,
            };
            $values = array_filter(array_map('trim', explode(',', (string) $rule['value'])));
            $passed = match ($rule['operator']) {
                'equals' => (string) $actual === (string) $rule['value'],
                'in' => in_array((string) $actual, $values, true),
                'greater_than_or_equal' => is_numeric($actual) && (int) $actual >= (int) $rule['value'],
                default => false,
            };
            $rules['announcement_rule_'.$index] = ['passed' => $passed, 'reason_code' => $passed ? null : 'announcement_rule_not_met'];
        }

        $failed = array_keys(array_filter($rules, fn (array $rule) => ! $rule['passed']));

        return [
            'eligible' => $failed === [],
            'status' => $failed === [] ? 'eligible' : 'not_eligible',
            'failed_rules' => $failed,
            'rules' => $rules,
            'context' => [
                'employee_id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'assignment_id' => $assignment?->id,
                'organization_id' => $assignment?->organization_id,
                'organization_unit_id' => $assignment?->organization_unit_id,
                'position_id' => $assignment?->position_id,
                'grade_level' => $sourcePosition?->grade_level,
                'destination_organization_id' => $destinationOrganizationId,
                'destination_position_id' => $destination?->id,
                'destination_grade_level' => $destinationGrade,
                'captured_at' => now()->toIso8601String(),
            ],
        ];
    }
}
