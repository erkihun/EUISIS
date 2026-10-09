<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class OfflineSyncTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'transactions' => ['required', 'array', 'min:1'],
            'transactions.*.token' => ['required', 'string'],
            'transactions.*.service_type' => ['required', 'string'],
            'transactions.*.provider_code' => ['required', 'string'],
        ];
    }
}
