<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Transport\Domain\Enums\TransportDirection;
use Modules\Transport\Http\Requests\Concerns\TransportProviderRequest;

/** A recurring departure of a route in one direction; admin pages name the provider, the portal never does. */
class StoreTransportScheduleRequest extends FormRequest
{
    use TransportProviderRequest;

    public function authorize(): bool
    {
        return $this->authorizeTransport($this->boundTransportRecord() ? 'transport-schedules.update' : 'transport-schedules.create', 'provider.transport.trips.manage');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'provider_id' => $this->providerIdRules(),
            'transport_route_id' => ['required', 'uuid', $this->ownedByProvider('transport_routes')],
            'direction' => ['required', Rule::in(TransportDirection::values())],
            'service_days' => ['required', 'array', 'min:1', 'max:7'],
            'service_days.*' => ['integer', 'between:1,7', 'distinct'],
            'planned_departure' => ['required', 'date_format:H:i'],
            'planned_arrival' => ['nullable', 'date_format:H:i', 'after:planned_departure'],
            'skips_public_holidays' => ['required', 'boolean'],
            'default_vehicle_id' => ['nullable', 'uuid', $this->ownedByProvider('transport_vehicles')],
            'default_driver_id' => ['nullable', 'uuid', $this->ownedByProvider('transport_drivers')],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function boundTransportRecord(): ?Model
    {
        $schedule = $this->route('schedule');

        return $schedule instanceof Model ? $schedule : null;
    }
}
