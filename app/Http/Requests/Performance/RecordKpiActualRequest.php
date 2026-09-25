<?php

declare(strict_types=1);

namespace App\Http\Requests\Performance;

use Illuminate\Foundation\Http\FormRequest;

/** Shape validation only; authorization and business rules live in the EPMS services. */
class RecordKpiActualRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authority (scope, manager, workflow state) is enforced by the service.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'actual_value' => ['nullable', 'numeric', 'required_without_all:actual_numerator,milestone_key'],
            'actual_numerator' => ['nullable', 'numeric', 'required_with:actual_denominator'],
            'actual_denominator' => ['nullable', 'numeric', 'min:0', 'required_with:actual_numerator'],
            'milestone_key' => ['nullable', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
