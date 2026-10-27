<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CafeteriaSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CafeteriaSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['key' => 'default_daily_subsidy_amount',    'value' => 0,                   'value_type' => 'numeric',  'group' => 'subsidy',  'label_en' => 'Default Daily Subsidy Amount'],
            ['key' => 'currency',                        'value' => 'ETB',               'value_type' => 'string',   'group' => 'general',  'label_en' => 'Currency'],
            ['key' => 'week_start_day',                  'value' => 'monday',            'value_type' => 'string',   'group' => 'days',     'label_en' => 'Week Start Day'],
            ['key' => 'week_end_day',                    'value' => 'friday',            'value_type' => 'string',   'group' => 'days',     'label_en' => 'Week End Day'],
            ['key' => 'default_usage_mode',              'value' => 'single_day',        'value_type' => 'string',   'group' => 'subsidy',  'label_en' => 'Default Usage Mode'],
            ['key' => 'allow_upfront_weekday_usage',     'value' => true,                'value_type' => 'boolean',  'group' => 'subsidy',  'label_en' => 'Allow Upfront Weekday Usage'],
            ['key' => 'allow_past_day_claim',            'value' => false,               'value_type' => 'boolean',  'group' => 'subsidy',  'label_en' => 'Allow Past Day Claim'],
            ['key' => 'allow_future_week_borrowing',     'value' => false,               'value_type' => 'boolean',  'group' => 'subsidy',  'label_en' => 'Allow Future Week Borrowing'],
            ['key' => 'exclude_public_holidays',         'value' => true,                'value_type' => 'boolean',  'group' => 'days',     'label_en' => 'Exclude Public Holidays'],
            ['key' => 'closed_weekend_default',          'value' => true,                'value_type' => 'boolean',  'group' => 'days',     'label_en' => 'Closed on Weekends by Default'],
            ['key' => 'allow_saturday_service',          'value' => false,               'value_type' => 'boolean',  'group' => 'days',     'label_en' => 'Allow Saturday Service'],
            ['key' => 'allow_sunday_service',            'value' => false,               'value_type' => 'boolean',  'group' => 'days',     'label_en' => 'Allow Sunday Service'],
            ['key' => 'weekend_scan_mode',               'value' => 'reject',            'value_type' => 'string',   'group' => 'scan',     'label_en' => 'Weekend Scan Mode'],
            ['key' => 'holiday_scan_mode',               'value' => 'reject',            'value_type' => 'string',   'group' => 'scan',     'label_en' => 'Holiday Scan Mode'],
            ['key' => 'excess_amount_mode',              'value' => 'employee_payable',  'value_type' => 'string',   'group' => 'subsidy',  'label_en' => 'Excess Amount Mode'],
            ['key' => 'require_active_employee',         'value' => true,                'value_type' => 'boolean',  'group' => 'general',  'label_en' => 'Require Active Employee'],
            ['key' => 'require_active_id_card',          'value' => true,                'value_type' => 'boolean',  'group' => 'general',  'label_en' => 'Require Active ID Card'],
            ['key' => 'require_provider_operator',       'value' => false,               'value_type' => 'boolean',  'group' => 'general',  'label_en' => 'Require Provider Operator'],
            ['key' => 'max_transaction_amount_per_scan', 'value' => null,                'value_type' => 'numeric',  'group' => 'scan',     'label_en' => 'Max Amount per Scan'],
            ['key' => 'max_extra_amount_per_week',       'value' => null,                'value_type' => 'numeric',  'group' => 'scan',     'label_en' => 'Max Extra Amount per Week'],
            ['key' => 'report_default_format',           'value' => 'csv',               'value_type' => 'string',   'group' => 'reports',  'label_en' => 'Default Report Format'],
            ['key' => 'report_timezone',                 'value' => 'Africa/Addis_Ababa', 'value_type' => 'string',   'group' => 'reports',  'label_en' => 'Report Timezone'],
            ['key' => 'payroll_cutoff_day',              'value' => null,                'value_type' => 'integer',  'group' => 'reports',  'label_en' => 'Payroll Cutoff Day'],
        ];

        foreach ($defaults as $row) {
            CafeteriaSetting::query()->updateOrCreate(
                ['key' => $row['key']],
                array_merge($row, ['id' => (string) Str::uuid()]),
            );
        }
    }
}
