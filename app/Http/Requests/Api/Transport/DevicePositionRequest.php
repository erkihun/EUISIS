<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Transport;

use Illuminate\Foundation\Http\FormRequest;

/** Vehicle positions for a trip (never an employee's). */
class DevicePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'positions' => ['required', 'array', 'min:1', 'max:500'],
            'positions.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'positions.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'positions.*.speed_kmh' => ['nullable', 'numeric', 'between:0,300'],
            'positions.*.heading' => ['nullable', 'integer', 'between:0,359'],
            'positions.*.recorded_at' => ['required', 'date'],
        ];
    }
}
