<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\EmployeeTransfer;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var EmployeeTransfer $transfer */
        $transfer = $this->route('employeeTransfer');

        return $this->user()?->can('update', $transfer) ?? false;
    }

    public function rules(): array
    {
        return [
            'to_organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'to_position_id' => ['nullable', 'uuid', 'exists:positions,id'],
            'effective_date' => ['required', 'date'],
            'transfer_reason' => ['nullable', 'string'],
        ];
    }
}
