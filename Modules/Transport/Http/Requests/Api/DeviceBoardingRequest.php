<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** One online boarding from a device: a credential, the active trip, an idempotency key. */
class DeviceBoardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the device middleware authenticated the caller; authority is checked by the engine
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'credential' => ['required', 'array'],
            'credential.type' => ['required', Rule::in(['qr', 'nfc'])],
            'credential.value' => ['required', 'string', 'min:10', 'max:2048'],
            'trip_id' => ['required', 'uuid'],
            'stop_id' => ['nullable', 'uuid'],
            'idempotency_key' => ['required', 'uuid'],
            'recorded_at' => ['nullable', 'date'],
        ];
    }
}
