<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\EmployeeTransfer;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', EmployeeTransfer::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid', 'exists:employees,id'],
            'to_organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'to_position_id' => ['nullable', 'uuid', 'exists:positions,id'],
            'effective_date' => ['required', 'date'],
            'transfer_reason' => ['nullable', 'string'],
        ];
    }
}
