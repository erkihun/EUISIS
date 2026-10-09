<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EmployeeTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('employees.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'position_id' => ['nullable', 'uuid', 'exists:positions,id'],
            'effective_date' => ['nullable', 'date', 'after_or_equal:today'],
            'reason' => ['nullable', 'string'],
        ];
    }
}
