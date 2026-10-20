<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\TransportProviderRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransportDriverRequest extends FormRequest
{
    use TransportProviderRequest;

    public function authorize(): bool
    {
        return $this->authorizeTransport('transport-drivers.create', 'provider.transport.drivers.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'provider_id' => $this->providerIdRules(),
            'full_name' => ['required', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'license_number' => ['nullable', 'string', 'max:255'],
            'license_expiry_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['active', 'inactive', 'suspended'])],
            'assigned_vehicle_id' => ['nullable', 'uuid', $this->ownedByProvider('transport_vehicles')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function boundTransportRecord(): ?Model
    {
        $driver = $this->route('driver');

        return $driver instanceof Model ? $driver : null;
    }
}
