<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransportVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('transport-vehicles.create') ?? false) || (auth('provider')->user()?->canUseServicePermission('provider.transport.vehicles.manage') ?? false);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'provider_id' => ['nullable', 'uuid', 'exists:providers,id'],
            'vehicle_code' => ['required', 'string', 'max:50'],
            'plate_number' => ['required', 'string', 'max:50'],
            'vehicle_type' => ['nullable', Rule::in(['bus', 'minibus', 'van', 'car', 'shuttle', 'other'])],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', Rule::in(['active', 'maintenance', 'inactive', 'suspended'])],
            'assigned_route_id' => ['nullable', 'uuid', 'exists:transport_routes,id'],
            'model' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:'.((int) date('Y') + 1)],
            'color' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
