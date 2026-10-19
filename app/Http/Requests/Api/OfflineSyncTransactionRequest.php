<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class OfflineSyncTransactionRequest extends FormRequest
{
    /** Largest batch one call may carry; a terminal syncs in pages beyond this. */
    public const MAX_ITEMS = 200;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'transactions' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'transactions.*.token' => ['required', 'string', 'max:255'],
            'transactions.*.service_type' => ['required', 'string', 'max:64'],
            'transactions.*.provider_code' => ['required', 'string', 'max:64'],
        ];
    }
}
