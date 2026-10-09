<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\EmployeeTransfer;
use Illuminate\Foundation\Http\FormRequest;

class RejectEmployeeTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var EmployeeTransfer $transfer */
        $transfer = $this->route('employeeTransfer');

        return $this->user()?->can('reject', $transfer) ?? false;
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string'],
        ];
    }
}
