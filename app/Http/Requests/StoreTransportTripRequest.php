<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransportTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('provider')->user()?->canUseServicePermission('provider.transport.trips.manage') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'transport_route_id' => ['required', 'uuid', 'exists:transport_routes,id'],
            'transport_vehicle_id' => ['nullable', 'uuid', 'exists:transport_vehicles,id'],
            'transport_driver_id' => ['nullable', 'uuid', 'exists:transport_drivers,id'],
            'trip_number' => ['nullable', 'string', 'max:80', 'unique:transport_trips,trip_number'],
            'trip_date' => ['required', 'date'],
            'departure_time' => ['nullable', 'date_format:H:i'],
            'arrival_time' => ['nullable', 'date_format:H:i'],
            'status' => ['required', Rule::in(['scheduled', 'boarding', 'departed', 'completed', 'cancelled'])],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
