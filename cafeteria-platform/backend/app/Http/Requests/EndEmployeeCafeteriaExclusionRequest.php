<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EndEmployeeCafeteriaExclusionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cafeteria_employee_exclusions.end');
    }

    public function rules(): array
    {
        return [
            'return_to_work_on' => ['nullable', 'date'],
        ];
    }
}
