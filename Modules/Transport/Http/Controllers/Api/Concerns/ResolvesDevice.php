<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;
use Modules\Transport\Domain\Models\TransportDevice;
use Modules\Transport\Domain\Models\TransportTrip;
use Modules\Transport\Http\Middleware\AuthenticateTransportDevice;

trait ResolvesDevice
{
    protected function device(Request $request): TransportDevice
    {
        /** @var TransportDevice $device */
        $device = $request->attributes->get(AuthenticateTransportDevice::ATTRIBUTE);

        return $device;
    }

    /** A trip of the device's provider (and, for a vehicle-bound device, of that vehicle); 404 otherwise. */
    protected function deviceTrip(TransportDevice $device, string $tripId): TransportTrip
    {
        return TransportTrip::query()
            ->with(['route', 'vehicle'])
            ->where('provider_id', $device->provider_id)
            ->when($device->transport_vehicle_id !== null, fn ($q) => $q->where('transport_vehicle_id', $device->transport_vehicle_id))
            ->whereKey($tripId)
            ->firstOrFail();
    }

    /** @return array<string, mixed> the compact trip the device shows */
    protected function tripPayload(TransportTrip $trip): array
    {
        return [
            'id' => $trip->id,
            'trip_number' => $trip->trip_number,
            'trip_date' => $trip->trip_date->toDateString(),
            'direction' => $trip->direction?->value,
            'status' => $trip->status->value,
            'departure_time' => $trip->departure_time !== null ? substr((string) $trip->departure_time, 0, 5) : null,
            'route' => $trip->route ? ['id' => $trip->route->id, 'route_code' => $trip->route->route_code, 'name_en' => $trip->route->name_en, 'name_am' => $trip->route->name_am] : null,
            'vehicle' => $trip->vehicle ? ['id' => $trip->vehicle->id, 'plate_number' => $trip->vehicle->plate_number, 'capacity' => $trip->vehicle->approvedCapacity()] : null,
            'boarded_count' => $trip->boarded_count,
            'capacity' => $trip->effectiveCapacity(),
            'occupancy_status' => $trip->occupancyStatus(),
            'stops' => $trip->route?->stops()->servingOn($trip->trip_date->toDateString())->get(['id', 'stop_code', 'name_en', 'name_am', 'sequence'])->toArray() ?? [],
        ];
    }
}
