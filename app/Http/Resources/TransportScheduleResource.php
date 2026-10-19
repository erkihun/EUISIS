<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransportScheduleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->provider_id,
            'transport_route_id' => $this->transport_route_id,
            'direction' => $this->direction?->value,
            'service_days' => array_map('intval', $this->service_days ?? []),
            'planned_departure' => $this->departureHm(),
            'planned_arrival' => $this->arrivalHm(),
            'skips_public_holidays' => (bool) $this->skips_public_holidays,
            'default_vehicle_id' => $this->default_vehicle_id,
            'default_driver_id' => $this->default_driver_id,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'route' => $this->whenLoaded('route', fn () => $this->route ? ['id' => $this->route->id, 'route_code' => $this->route->route_code, 'name_en' => $this->route->name_en, 'name_am' => $this->route->name_am] : null),
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? ['id' => $this->provider->id, 'provider_code' => $this->provider->provider_code, 'name_en' => $this->provider->name_en, 'name_am' => $this->provider->name_am] : null),
            'default_vehicle' => $this->whenLoaded('defaultVehicle', fn () => $this->defaultVehicle ? ['id' => $this->defaultVehicle->id, 'plate_number' => $this->defaultVehicle->plate_number] : null),
        ];
    }
}
