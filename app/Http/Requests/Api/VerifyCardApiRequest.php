<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class VerifyCardApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'service_type' => ['required', 'string', 'exists:service_types,code'],
            'provider_code' => ['nullable', 'string', 'exists:service_providers,code'],
            'device_identifier' => ['nullable', 'string'],
        ];
    }
}
