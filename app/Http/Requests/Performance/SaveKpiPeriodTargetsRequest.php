<?php

declare(strict_types=1);

namespace App\Http\Requests\Performance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveKpiPeriodTargetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'period_targets' => ['required', 'array', 'max:16'],
            'period_targets.*.period_type' => ['required', Rule::in(['QUARTER', 'MONTH'])],
            'period_targets.*.period_number' => ['required', 'integer', 'between:1,12'],
            'period_targets.*.target_value' => ['nullable', 'numeric', 'required_without_all:period_targets.*.target_numerator,period_targets.*.target_denominator'],
            'period_targets.*.target_numerator' => ['nullable', 'numeric', 'required_with:period_targets.*.target_denominator'],
            'period_targets.*.target_denominator' => ['nullable', 'numeric', 'not_in:0', 'required_with:period_targets.*.target_numerator'],
            'period_targets.*.is_cumulative' => ['sometimes', 'boolean'],
        ];
    }
}
