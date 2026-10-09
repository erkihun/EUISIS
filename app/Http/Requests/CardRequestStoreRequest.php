<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CardRequestStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cards.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid', 'exists:employees,id'],
            'request_reason' => ['nullable', 'string'],
        ];
    }
}
