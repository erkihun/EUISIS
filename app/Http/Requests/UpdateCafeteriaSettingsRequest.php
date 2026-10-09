<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Cafeteria\CafeteriaSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCafeteriaSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cafeteria_settings.update');
    }

    public function rules(): array
    {
        $rules = [
            'default_daily_subsidy_amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'required', 'string', 'regex:/^[A-Z]{3}$/'],
            'week_start_day' => ['nullable', 'string', Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])],
            'week_end_day' => ['nullable', 'string', Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])],
            'default_usage_mode' => ['nullable', 'string', Rule::in(['single_day', 'use_remaining_week'])],
            'allow_upfront_weekday_usage' => ['nullable', 'boolean'],
            'allow_past_day_claim' => ['nullable', 'boolean'],
            'allow_future_week_borrowing' => ['nullable', 'boolean'],
            'exclude_public_holidays' => ['nullable', 'boolean'],
            'closed_weekend_default' => ['nullable', 'boolean'],
            'allow_saturday_service' => ['nullable', 'boolean'],
            'allow_sunday_service' => ['nullable', 'boolean'],
            'weekend_scan_mode' => ['nullable', 'string', Rule::in(['reject', 'allow', 'employee_payable'])],
            'holiday_scan_mode' => ['nullable', 'string', Rule::in(['reject', 'allow', 'employee_payable'])],
            'excess_amount_mode' => ['nullable', 'string', Rule::in(['employee_payable', 'reject'])],
            'require_active_employee' => ['nullable', 'boolean'],
            'require_active_id_card' => ['nullable', 'boolean'],
            'require_provider_operator' => ['nullable', 'boolean'],
            'max_transaction_amount_per_scan' => ['nullable', 'numeric', 'min:0'],
            'max_extra_amount_per_week' => ['nullable', 'numeric', 'min:0'],
            'payroll_cutoff_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'report_default_format' => ['nullable', 'string', Rule::in(['csv', 'xlsx', 'pdf'])],
            'report_timezone' => ['nullable', 'string', 'timezone'],
            // Employee leave access control
            'block_cafeteria_during_employee_leave' => ['nullable', 'boolean'],
            'leave_scan_mode' => ['nullable', 'string', Rule::in(['reject', 'employee_payable'])],
            'exclude_leave_days_from_subsidy' => ['nullable', 'boolean'],
            'allow_leave_day_retroactive_claim' => ['nullable', 'boolean'],
            'auto_resume_after_leave' => ['nullable', 'boolean'],
        ];

        foreach (CafeteriaSettingsService::READ_ONLY as $key => $reason) {
            $rules[$key] = ['missing'];
        }
        foreach (app(CafeteriaSettingsService::class)->editableKeys() as $key) {
            // Optional limits may be cleared; operating rules must have a value.
            if (! in_array($key, ['max_transaction_amount_per_scan', 'max_extra_amount_per_week'], true)) {
                $rules[$key] = array_values(array_filter($rules[$key], fn ($rule) => $rule !== 'nullable'));
                $rules[$key][] = 'sometimes';
                $rules[$key][] = 'required';
            }
        }

        return $rules;
    }
}
