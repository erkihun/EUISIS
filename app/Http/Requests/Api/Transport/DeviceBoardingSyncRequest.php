<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Transport;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A batch of boardings the device recorded while offline, each with its own uuid as idempotency key. */
class DeviceBoardingSyncRequest extends FormRequest
{
    public const MAX_ITEMS = 200;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'boardings' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'boardings.*.offline_uuid' => ['required', 'uuid', 'distinct'],
            'boardings.*.credential' => ['required', 'array'],
            'boardings.*.credential.type' => ['required', Rule::in(['qr', 'nfc'])],
            'boardings.*.credential.value' => ['required', 'string', 'min:10', 'max:2048'],
            'boardings.*.trip_id' => ['required', 'uuid'],
            'boardings.*.stop_id' => ['nullable', 'uuid'],
            'boardings.*.recorded_at' => ['required', 'date'],
        ];
    }
}
