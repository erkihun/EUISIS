<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ServiceTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'provider_code' => ['required', 'string', 'exists:service_providers,code'],
            'reference' => ['nullable', 'string'],
            'amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
