<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransportDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('transport-drivers.create') ?? false) || (auth('provider')->user()?->canUseServicePermission('provider.transport.drivers.manage') ?? false);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'provider_id' => ['nullable', 'uuid', 'exists:providers,id'],
            'full_name' => ['required', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'license_number' => ['nullable', 'string', 'max:255'],
            'license_expiry_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['active', 'inactive', 'suspended'])],
            'assigned_vehicle_id' => ['nullable', 'uuid', 'exists:transport_vehicles,id'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
