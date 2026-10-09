<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CardIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cards.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['lost', 'damaged', 'suspended'])],
            'reason' => ['nullable', 'string'],
        ];
    }
}
