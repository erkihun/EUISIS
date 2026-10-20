<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\TransportProviderRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransportVehicleRequest extends FormRequest
{
    use TransportProviderRequest;

    public function authorize(): bool
    {
        return $this->authorizeTransport('transport-vehicles.create', 'provider.transport.vehicles.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $vehicle = $this->boundTransportRecord();

        return [
            'provider_id' => $this->providerIdRules(),
            'vehicle_code' => ['required', 'string', 'max:50', $this->uniqueForProvider('transport_vehicles', 'vehicle_code')],
            // A plate is unique on the road, whichever provider runs it.
            'plate_number' => ['required', 'string', 'max:50', Rule::unique('transport_vehicles', 'plate_number')->ignore($vehicle?->getKey())],
            'vehicle_type' => ['nullable', Rule::in(['bus', 'minibus', 'van', 'car', 'shuttle', 'other'])],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:500'],
            'status' => ['required', Rule::in(['active', 'maintenance', 'inactive', 'suspended'])],
            'assigned_route_id' => ['nullable', 'uuid', $this->ownedByProvider('transport_routes')],
            'model' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:'.((int) date('Y') + 1)],
            'color' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function boundTransportRecord(): ?Model
    {
        $vehicle = $this->route('vehicle');

        return $vehicle instanceof Model ? $vehicle : null;
    }
}
