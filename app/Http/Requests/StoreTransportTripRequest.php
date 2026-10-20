<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Transport\TransportDirection;
use App\Enums\Transport\TransportTripStatus;
use App\Http\Requests\Concerns\TransportProviderRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Trips are planned by the provider itself, in the portal. */
class StoreTransportTripRequest extends FormRequest
{
    use TransportProviderRequest;

    public function authorize(): bool
    {
        return $this->onPortal() && $this->authorizeTransport('transport-trips.manage', 'provider.transport.trips.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'transport_route_id' => ['required', 'uuid', $this->ownedByProvider('transport_routes')],
            'transport_vehicle_id' => ['nullable', 'uuid', $this->ownedByProvider('transport_vehicles')],
            'transport_driver_id' => ['nullable', 'uuid', $this->ownedByProvider('transport_drivers')],
            'trip_number' => ['nullable', 'string', 'max:80', Rule::unique('transport_trips', 'trip_number')->ignore($this->boundTransportRecord()?->getKey())],
            'trip_date' => ['required', 'date'],
            'departure_time' => ['nullable', 'date_format:H:i'],
            'arrival_time' => ['nullable', 'date_format:H:i', 'after:departure_time'],
            // A new trip is planned (or assigned when it already names a vehicle); it is
            // started, completed or cancelled through dispatch, never by editing the status.
            'status' => ['required', Rule::in([TransportTripStatus::Planned->value, TransportTripStatus::Assigned->value])],
            'direction' => ['required', Rule::in(TransportDirection::values())],
            'transport_schedule_id' => ['nullable', 'uuid', $this->ownedByProvider('transport_schedules')],
            'expected_riders' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function boundTransportRecord(): ?Model
    {
        $trip = $this->route('trip');

        return $trip instanceof Model ? $trip : null;
    }
}
