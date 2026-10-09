<?php

declare(strict_types=1);

namespace App\Services\Cafeteria;

use App\Models\CafeteriaSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CafeteriaSettingsService
{
    private const CACHE_KEY = 'cafeteria_settings_all';

    private const CACHE_TTL = 3600; // 1 hour

    /** These are fixed service rules or preferences without an implementation. */
    public const READ_ONLY = [
        'require_active_employee' => 'eligibility',
        'require_active_id_card' => 'eligibility',
        'require_provider_operator' => 'permissions',
        'allow_past_day_claim' => 'currentWindow',
        'allow_future_week_borrowing' => 'currentWindow',
        'allow_leave_day_retroactive_claim' => 'currentWindow',
        'auto_resume_after_leave' => 'leaveDates',
        'payroll_cutoff_day' => 'notImplemented',
        'report_default_format' => 'reportActions',
        'report_timezone' => 'applicationTimezone',
        // Financial values come only from organization Service Policies; never a global fallback.
        'default_daily_subsidy_amount' => 'policyOnly',
    ];

    public function editableKeys(): array
    {
        return array_values(array_diff(array_keys(self::DEFAULTS), array_keys(self::READ_ONLY)));
    }

    public function defaultUsageMode(): string
    {
        return $this->getBool('allow_upfront_weekday_usage')
            ? (string) $this->get('default_usage_mode', 'single_day')
            : 'single_day';
    }

    public function scanOptions(): array
    {
        return ['default_usage_mode' => $this->defaultUsageMode(), 'allow_upfront_weekday_usage' => $this->getBool('allow_upfront_weekday_usage')];
    }

    /** Show effective fixed rules without overwriting previously saved preferences. */
    public function effectiveForForm(): array
    {
        return array_replace($this->all(), [
            'require_active_employee' => true, 'require_active_id_card' => true,
            'allow_past_day_claim' => false, 'allow_future_week_borrowing' => false,
            'allow_leave_day_retroactive_claim' => false, 'auto_resume_after_leave' => true,
            'report_timezone' => config('app.timezone'),
        ]);
    }

    /** Default values returned when the DB row does not exist yet. */
    private const DEFAULTS = [
        'default_daily_subsidy_amount' => 0,
        'currency' => 'ETB',
        'week_start_day' => 'monday',
        'week_end_day' => 'friday',
        'default_usage_mode' => 'single_day',
        'allow_upfront_weekday_usage' => true,
        'allow_past_day_claim' => false,
        'allow_future_week_borrowing' => false,
        'exclude_public_holidays' => true,
        'closed_weekend_default' => true,
        'allow_saturday_service' => false,
        'allow_sunday_service' => false,
        'weekend_scan_mode' => 'reject',
        'holiday_scan_mode' => 'reject',
        'excess_amount_mode' => 'employee_payable',
        'require_active_employee' => true,
        'require_active_id_card' => true,
        'require_provider_operator' => false,
        'max_transaction_amount_per_scan' => null,
        'max_extra_amount_per_week' => null,
        'payroll_cutoff_day' => null,
        'report_default_format' => 'csv',
        'report_timezone' => 'Africa/Addis_Ababa',
        // Employee leave access control
        'block_cafeteria_during_employee_leave' => true,
        'leave_scan_mode' => 'reject',
        'exclude_leave_days_from_subsidy' => true,
        'allow_leave_day_retroactive_claim' => false,
        'auto_resume_after_leave' => true,
    ];

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return $all[$key] ?? $default ?? self::DEFAULTS[$key] ?? null;
    }

    public function getBool(string $key): bool
    {
        return (bool) $this->get($key, self::DEFAULTS[$key] ?? false);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            $rows = CafeteriaSetting::query()->get()->keyBy('key');
            $merged = self::DEFAULTS;

            foreach ($rows as $key => $row) {
                $merged[$key] = $row->value;
            }

            return $merged;
        });
    }

    /**
     * Persist a batch of settings.
     *
     * @param  array<string, mixed>  $settings
     */
    public function setMany(array $settings): void
    {
        foreach ($settings as $key => $value) {
            CafeteriaSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'group' => $this->inferGroup($key)],
            );
        }

        $this->clearCache();
        // A concurrent reader can refill the cache before an outer transaction commits.
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->clearCache());
        }
    }

    public function set(string $key, mixed $value): void
    {
        $this->setMany([$key => $value]);
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** Groups for the UI tabs. */
    private function inferGroup(string $key): string
    {
        return match (true) {
            str_starts_with($key, 'report_') || str_starts_with($key, 'payroll_') => 'reports',
            in_array($key, ['currency', 'require_active_employee', 'require_active_id_card', 'require_provider_operator']) => 'general',
            in_array($key, ['default_daily_subsidy_amount', 'allow_upfront_weekday_usage', 'allow_past_day_claim', 'allow_future_week_borrowing', 'excess_amount_mode', 'default_usage_mode']) => 'subsidy',
            in_array($key, ['closed_weekend_default', 'allow_saturday_service', 'allow_sunday_service', 'week_start_day', 'week_end_day']) => 'days',
            in_array($key, ['exclude_public_holidays']) => 'days',
            in_array($key, ['weekend_scan_mode', 'holiday_scan_mode', 'max_transaction_amount_per_scan', 'max_extra_amount_per_week',
                'block_cafeteria_during_employee_leave', 'leave_scan_mode', 'exclude_leave_days_from_subsidy',
                'allow_leave_day_retroactive_claim', 'auto_resume_after_leave']) => 'scan',
            default => 'general',
        };
    }
}
