<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Transport\Domain\Models\TransportTripPosition;
use Modules\Transport\Http\Controllers\Api\Concerns\ResolvesDevice;
use Modules\Transport\Http\Requests\Api\DevicePositionRequest;

/**
 * Vehicle positions during a trip (feature transport_gps). This is the
 * vehicle's track, kept for trip monitoring and route adherence; it is never
 * an employee's location and basic usage accounting does not depend on it.
 */
class PositionController extends Controller
{
    use ResolvesDevice;

    public function store(DevicePositionRequest $request, string $trip): JsonResponse
    {
        if (! Features::enabled('transport_gps')) {
            return response()->json(['error_code' => 'gps_disabled'], 409);
        }

        $device = $this->device($request);
        $trip = $this->deviceTrip($device, $trip);
        if ($trip->status->isTerminal()) {
            return response()->json(['error_code' => 'trip_closed'], 409);
        }

        $now = now();
        $rows = array_map(fn (array $p): array => [
            'id' => (string) Str::uuid7(),
            'transport_trip_id' => $trip->id,
            'transport_device_id' => $device->id,
            'latitude' => $p['latitude'],
            'longitude' => $p['longitude'],
            'speed_kmh' => $p['speed_kmh'] ?? null,
            'heading' => $p['heading'] ?? null,
            'recorded_at' => Carbon::parse($p['recorded_at'])->setTimezone(config('app.timezone')),
            'created_at' => $now,
        ], $request->validated('positions'));

        TransportTripPosition::query()->insert($rows);

        return response()->json(['stored' => count($rows)], 201);
    }
}
