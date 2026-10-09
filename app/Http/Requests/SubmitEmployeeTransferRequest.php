<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\EmployeeTransfer;
use Illuminate\Foundation\Http\FormRequest;

class SubmitEmployeeTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var EmployeeTransfer $transfer */
        $transfer = $this->route('employeeTransfer');

        return $this->user()?->can('submit', $transfer) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
