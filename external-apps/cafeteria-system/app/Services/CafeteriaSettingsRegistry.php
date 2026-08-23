<?php

declare(strict_types=1);

namespace CafeteriaSystem\Services;

/**
 * Canonical cafeteria settings definition.
 *
 * Mirrors the setting keys used by the EUISIS cafeteria module so a provider
 * configures the same concepts here. Held as a typed registry (rather than
 * free-form rows) so the UI can render the right control and validation knows
 * what each key accepts.
 */
final class CafeteriaSettingsRegistry
{
    /**
     * tab => [key => [type, label, options?]]
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function definition(): array
    {
        return [
            'general' => [
                'currency' => ['type' => 'string', 'label' => 'Currency', 'default' => 'ETB'],
                'require_active_employee' => ['type' => 'boolean', 'label' => 'Require active employee', 'default' => true],
                'require_active_id_card' => ['type' => 'boolean', 'label' => 'Require active ID card', 'default' => true],
                'require_provider_operator' => ['type' => 'boolean', 'label' => 'Require provider operator', 'default' => false],
            ],
            'subsidy' => [
                'default_daily_subsidy_amount' => ['type' => 'decimal', 'label' => 'Default daily subsidy amount', 'default' => '0'],
                'max_transaction_amount_per_scan' => ['type' => 'decimal', 'label' => 'Max amount per scan', 'default' => ''],
                'max_extra_amount_per_week' => ['type' => 'decimal', 'label' => 'Max extra amount per week', 'default' => ''],
                'excess_amount_mode' => [
                    'type' => 'select', 'label' => 'Excess amount mode', 'default' => 'employee_payable',
                    'options' => ['employee_payable', 'reject', 'provider_absorbed'],
                ],
            ],
            'days' => [
                'week_start_day' => [
                    'type' => 'select', 'label' => 'Week start day', 'default' => 'monday',
                    'options' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                ],
                'week_end_day' => [
                    'type' => 'select', 'label' => 'Week end day', 'default' => 'friday',
                    'options' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                ],
                'closed_weekend_default' => ['type' => 'boolean', 'label' => 'Closed on weekends by default', 'default' => true],
                'allow_saturday_service' => ['type' => 'boolean', 'label' => 'Allow Saturday service', 'default' => false],
                'allow_sunday_service' => ['type' => 'boolean', 'label' => 'Allow Sunday service', 'default' => false],
            ],
            'scan' => [
                'default_usage_mode' => [
                    'type' => 'select', 'label' => 'Default usage mode', 'default' => 'single_day',
                    'options' => ['single_day', 'use_remaining_week'],
                ],
                'allow_past_day_claim' => ['type' => 'boolean', 'label' => 'Allow past-day claim', 'default' => false],
                'allow_upfront_weekday_usage' => ['type' => 'boolean', 'label' => 'Allow upfront weekday usage', 'default' => true],
                'allow_future_week_borrowing' => ['type' => 'boolean', 'label' => 'Allow future-week borrowing', 'default' => false],
            ],
            'day-rules' => [
                'weekend_scan_mode' => [
                    'type' => 'select', 'label' => 'Weekend scan mode', 'default' => 'reject',
                    'options' => ['reject', 'allow', 'allow_no_subsidy'],
                ],
                'holiday_scan_mode' => [
                    'type' => 'select', 'label' => 'Holiday scan mode', 'default' => 'reject',
                    'options' => ['reject', 'allow', 'allow_no_subsidy'],
                ],
                'leave_scan_mode' => [
                    'type' => 'select', 'label' => 'Employee-leave scan mode', 'default' => 'reject',
                    'options' => ['reject', 'allow', 'allow_no_subsidy'],
                ],
            ],
            'holidays' => [
                'exclude_public_holidays' => ['type' => 'boolean', 'label' => 'Exclude public holidays from subsidy', 'default' => true],
            ],
            'subsidy-rules' => [
                'exclude_leave_days_from_subsidy' => ['type' => 'boolean', 'label' => 'Exclude leave days from subsidy', 'default' => true],
                'block_cafeteria_during_employee_leave' => ['type' => 'boolean', 'label' => 'Block service during employee leave', 'default' => true],
                'allow_leave_day_retroactive_claim' => ['type' => 'boolean', 'label' => 'Allow retroactive leave-day claim', 'default' => false],
                'auto_resume_after_leave' => ['type' => 'boolean', 'label' => 'Auto-resume after leave', 'default' => true],
            ],
            'reports' => [
                'report_default_format' => [
                    'type' => 'select', 'label' => 'Default report format', 'default' => 'csv',
                    'options' => ['csv', 'pdf', 'xlsx'],
                ],
                'report_timezone' => ['type' => 'string', 'label' => 'Report timezone', 'default' => 'Africa/Addis_Ababa'],
                'payroll_cutoff_day' => ['type' => 'integer', 'label' => 'Payroll cutoff day', 'default' => ''],
            ],
        ];
    }

    /** Tab keys in display order. Provider Users is a live list, not settings. */
    public static function tabs(): array
    {
        return [...array_keys(self::definition()), 'provider-users'];
    }

    /** Flat key => definition map, for validation. */
    public static function flat(): array
    {
        return array_merge(...array_values(self::definition()));
    }
}
