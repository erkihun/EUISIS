<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Transport\Application\Services\TransportTripService;
use Modules\Transport\Domain\Enums\TransportTripStatus;
use Modules\Transport\Domain\Models\TransportTrip;
use Modules\Transport\Http\Controllers\Api\Concerns\ResolvesDevice;

/**
 * The trip context a device works in: today's trips of its provider (or
 * its vehicle), and the lifecycle actions an operator triggers from the
 * device. The device never names a route, vehicle or provider of its own.
 */
class TripController extends Controller
{
    use ResolvesDevice;

    public function today(Request $request): JsonResponse
    {
        $device = $this->device($request);

        $trips = TransportTrip::query()
            ->with(['route', 'vehicle'])
            ->where('provider_id', $device->provider_id)
            ->when($device->transport_vehicle_id !== null, fn ($q) => $q->where('transport_vehicle_id', $device->transport_vehicle_id))
            ->whereDate('trip_date', today())
            ->whereIn('status', [TransportTripStatus::Planned->value, TransportTripStatus::Assigned->value, TransportTripStatus::Boarding->value, TransportTripStatus::InProgress->value])
            ->orderBy('departure_time')
            ->limit(50)
            ->get();

        return response()->json(['data' => $trips->map(fn (TransportTrip $trip): array => $this->tripPayload($trip))->all(), 'server_time' => now()->toIso8601String()]);
    }

    public function show(Request $request, string $trip): JsonResponse
    {
        return response()->json(['data' => $this->tripPayload($this->deviceTrip($this->device($request), $trip))]);
    }

    public function start(Request $request, string $trip, TransportTripService $trips): JsonResponse
    {
        return $this->transition(fn (TransportTrip $t) => $trips->start($t, null, $request, $this->device($request)->id), $request, $trip);
    }

    public function depart(Request $request, string $trip, TransportTripService $trips): JsonResponse
    {
        return $this->transition(fn (TransportTrip $t) => $trips->depart($t, null, $request), $request, $trip);
    }

    public function complete(Request $request, string $trip, TransportTripService $trips): JsonResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:1000'], 'distance_km' => ['nullable', 'numeric', 'min:0', 'max:9999']]);

        return $this->transition(fn (TransportTrip $t) => $trips->complete($t, null, $request, $data['notes'] ?? null, isset($data['distance_km']) ? (string) $data['distance_km'] : null), $request, $trip);
    }

    /** @param  callable(TransportTrip): TransportTrip  $action */
    private function transition(callable $action, Request $request, string $tripId): JsonResponse
    {
        $trip = $this->deviceTrip($this->device($request), $tripId);

        try {
            $trip = $action($trip);
        } catch (DomainException $e) {
            return response()->json(['error_code' => 'trip_transition_invalid', 'message' => $e->getMessage(), 'data' => $this->tripPayload($trip->refresh()->load(['route', 'vehicle']))], 409);
        }

        return response()->json(['data' => $this->tripPayload($trip->load(['route', 'vehicle']))]);
    }
}
