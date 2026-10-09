<?php

declare(strict_types=1);

namespace App\Http\Requests\Performance;

use App\Enums\Performance\KpiFrequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Shape validation only; authorization and business rules live in the EPMS services. */
class SaveKpiTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authority (scope, manager, workflow state) is enforced by the service.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'kpi_id' => [$this->isMethod('post') ? 'required' : 'prohibited', 'uuid', 'exists:kpis,id'],
            'parent_target_id' => ['nullable', 'uuid', 'exists:kpi_targets,id'],
            'period_type' => ['nullable', Rule::enum(KpiFrequency::class)],
            'period_start' => ['nullable', 'date_format:Y-m-d'],
            'period_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'baseline_value' => ['nullable', 'numeric'],
            'target_value' => ['nullable', 'numeric'],
            'target_numerator' => ['nullable', 'numeric', 'required_with:target_denominator'],
            'target_denominator' => ['nullable', 'numeric', 'not_in:0', 'required_with:target_numerator'],
            'weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'achievement_cap' => ['nullable', 'numeric', 'min:100', 'max:200'],
            'tolerance' => ['nullable', 'numeric', 'min:0'],
            'zero_score_deviation' => ['nullable', 'numeric', 'gt:0'],
            'minimum_acceptable_value' => ['nullable', 'numeric'],
            'stretch_target' => ['nullable', 'numeric'],
        ];
    }
}
