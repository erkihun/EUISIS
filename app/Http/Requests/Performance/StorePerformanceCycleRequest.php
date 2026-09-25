<?php

declare(strict_types=1);

namespace App\Http\Requests\Performance;

use Illuminate\Foundation\Http\FormRequest;

/** Shape validation only; authorization and business rules live in the EPMS services. */
class StorePerformanceCycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('performance_cycles.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:performance_cycles,code'],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'planning_start_date' => ['nullable', 'date_format:Y-m-d'],
            'planning_end_date' => ['nullable', 'date_format:Y-m-d'],
            'midyear_review_start_date' => ['nullable', 'date_format:Y-m-d'],
            'midyear_review_end_date' => ['nullable', 'date_format:Y-m-d'],
            'yearend_review_start_date' => ['nullable', 'date_format:Y-m-d'],
            'yearend_review_end_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
