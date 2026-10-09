<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CardIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cards.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'recipient_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
