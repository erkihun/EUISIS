<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CafeteriaExclusionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeCafeteriaExclusionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cafeteria_employee_exclusions.create');
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid', 'exists:employees,id'],
            'exclusion_type' => ['required', Rule::in(array_column(CafeteriaExclusionType::cases(), 'value'))],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'return_to_work_on' => ['nullable', 'date'],
            'is_open_ended' => ['boolean'],
            'reason_en' => ['nullable', 'string', 'max:2000'],
            'reason_am' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
