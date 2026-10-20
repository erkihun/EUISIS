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
            'transport_schedule_id' => $this->transport_schedule_id,
            'trip_number' => $this->trip_number,
            'direction' => $this->direction?->value,
            'trip_date' => $this->trip_date?->toDateString(),
            // HH:MM, as the form posts it back (Postgres returns HH:MM:SS).
            'departure_time' => $this->departure_time !== null ? substr((string) $this->departure_time, 0, 5) : null,
            'arrival_time' => $this->arrival_time !== null ? substr((string) $this->arrival_time, 0, 5) : null,
            'planned_departure_at' => $this->planned_departure_at?->toIso8601String(),
            'planned_arrival_at' => $this->planned_arrival_at?->toIso8601String(),
            'actual_started_at' => $this->actual_started_at?->toIso8601String(),
            'actual_ended_at' => $this->actual_ended_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'status' => $this->status?->value,
            'capacity' => $this->capacity,
            'effective_capacity' => $this->effectiveCapacity(),
            'boarded_count' => $this->boarded_count,
            'expected_riders' => $this->expected_riders,
            'utilization_percent' => $this->utilizationPercent(),
            'occupancy_status' => $this->occupancyStatus(),
            'is_extra' => (bool) $this->is_extra,
            'distance_km' => $this->distance_km,
            'notes' => $this->notes,
            'completion_notes' => $this->completion_notes,
            'route' => $this->whenLoaded('route', fn () => $this->route ? [
                'id' => $this->route->id,
                'name_en' => $this->route->name_en,
                'name_am' => $this->route->name_am,
                'route_code' => $this->route->route_code,
            ] : null),
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle ? [
                'id' => $this->vehicle->id,
                'vehicle_code' => $this->vehicle->vehicle_code,
                'plate_number' => $this->vehicle->plate_number,
                'capacity' => $this->vehicle->approvedCapacity(),
            ] : null),
            'driver' => $this->whenLoaded('driver', fn () => $this->driver ? [
                'id' => $this->driver->id,
                'full_name' => $this->driver->full_name,
            ] : null),
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? [
                'id' => $this->provider->id,
                'provider_code' => $this->provider->provider_code,
                'name_en' => $this->provider->name_en,
                'name_am' => $this->provider->name_am,
            ] : null),
            'vehicle_changes' => $this->whenLoaded('vehicleChanges', fn () => $this->vehicleChanges->map(fn ($change): array => [
                'id' => $change->id,
                'from_vehicle_id' => $change->from_vehicle_id,
                'to_vehicle_id' => $change->to_vehicle_id,
                'from_plate' => $change->fromVehicle?->plate_number,
                'to_plate' => $change->toVehicle?->plate_number,
                'reason' => $change->reason,
                'changed_at' => $change->changed_at?->toIso8601String(),
            ])->all()),
        ];
    }
}
