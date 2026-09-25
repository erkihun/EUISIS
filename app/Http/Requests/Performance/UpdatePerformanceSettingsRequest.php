<?php

declare(strict_types=1);

namespace App\Http\Requests\Performance;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePerformanceSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('performance_settings.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'results_weight' => ['required', 'integer', 'min:0', 'max:100'],
            // Results + competency must total 100.
            'competency_weight' => ['required', 'integer', 'min:0', 'max:100', function (string $attribute, mixed $value, \Closure $fail): void {
                if ((int) $value + (int) $this->input('results_weight') !== 100) {
                    $fail(__('performance.validation.component_weights', ['total' => (int) $value + (int) $this->input('results_weight')]));
                }
            }],
            'default_achievement_cap' => ['required', 'integer', 'min:100', 'max:200'],
            'require_employee_acknowledgement' => ['required', 'boolean'],
            'require_midyear_review' => ['required', 'boolean'],
            'require_yearend_self_assessment' => ['required', 'boolean'],
            'require_calibration' => ['required', 'boolean'],
            'require_result_release' => ['required', 'boolean'],
            'allow_manual_kpi_actual' => ['required', 'boolean'],
            'allow_score_adjustment' => ['required', 'boolean'],
            'appeal_window_days' => ['required', 'integer', 'min:0', 'max:90'],
            'checkin_frequency' => ['required', 'in:monthly,quarterly'],
            'pip_threshold' => ['required', 'integer', 'min:0', 'max:100'],
            'at_risk_threshold' => ['required', 'integer', 'min:1', 'max:100'],
            'off_track_threshold' => ['required', 'integer', 'min:0', 'max:100', 'lte:at_risk_threshold'],
            'amendment_requires_approval' => ['required', 'boolean'],
            'allow_self_approval' => ['required', 'boolean'],
            'prorate_transfer_results' => ['required', 'boolean'],
        ];
    }
}
