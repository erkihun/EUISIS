<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Transport\Domain\Enums\TransportDirection;
use Modules\Transport\Http\Requests\Concerns\TransportProviderRequest;

/** An extra trip planned by staff (outside the provider's schedule), on the chosen provider's own route and vehicle. */
class AdminTransportTripRequest extends FormRequest
{
    use TransportProviderRequest;

    public function authorize(): bool
    {
        return ! $this->onPortal() && ($this->user()?->can('transport-trips.create') ?? false);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'provider_id' => $this->providerIdRules(),
            'transport_route_id' => ['required', 'uuid', $this->ownedByProvider('transport_routes')],
            'transport_vehicle_id' => ['nullable', 'uuid', $this->ownedByProvider('transport_vehicles')],
            'transport_driver_id' => ['nullable', 'uuid', $this->ownedByProvider('transport_drivers')],
            'direction' => ['required', Rule::in(TransportDirection::values())],
            'trip_date' => ['required', 'date'],
            'departure_time' => ['nullable', 'date_format:H:i'],
            'arrival_time' => ['nullable', 'date_format:H:i', 'after:departure_time'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function boundTransportRecord(): ?Model
    {
        return null;
    }
}
