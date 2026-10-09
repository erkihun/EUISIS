<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransportTripResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->provider_id,
            'transport_route_id' => $this->transport_route_id,
            'transport_vehicle_id' => $this->transport_vehicle_id,
            'transport_driver_id' => $this->transport_driver_id,
            'trip_number' => $this->trip_number,
            'trip_date' => $this->trip_date?->toDateString(),
            'departure_time' => $this->departure_time,
            'arrival_time' => $this->arrival_time,
            'status' => $this->status,
            'capacity' => $this->capacity,
            'boarded_count' => $this->boarded_count,
            'route' => $this->whenLoaded('route', fn () => $this->route ? [
                'id' => $this->route->id,
                'name_en' => $this->route->name_en,
                'route_code' => $this->route->route_code,
            ] : null),
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle ? [
                'id' => $this->vehicle->id,
                'plate_number' => $this->vehicle->plate_number,
            ] : null),
            'driver' => $this->whenLoaded('driver', fn () => $this->driver ? [
                'id' => $this->driver->id,
                'full_name' => $this->driver->full_name,
            ] : null),
        ];
    }
}
