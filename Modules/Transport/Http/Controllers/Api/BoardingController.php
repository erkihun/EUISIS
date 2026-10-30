<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Modules\Transport\Application\Services\Boarding\BoardingDecision;
use Modules\Transport\Application\Services\Boarding\BoardingRequest;
use Modules\Transport\Application\Services\Boarding\TransportBoardingService;
use Modules\Transport\Domain\Enums\TransportVerificationMethod;
use Modules\Transport\Domain\Models\TransportTransaction;
use Modules\Transport\Http\Controllers\Api\Concerns\ResolvesDevice;
use Modules\Transport\Http\Requests\Api\DeviceBoardingRequest;
use Modules\Transport\Http\Requests\Api\DeviceBoardingSyncRequest;

/**
 * POST /api/v1/transport/boardings/verify — one scan, one compact answer.
 * POST /api/v1/transport/boardings/sync   — boardings recorded offline.
 *
 * The response carries the minimum the operator needs (name, number, trip,
 * reason); never the employee's organization details beyond the name, and
 * never HR data.
 */
class BoardingController extends Controller
{
    use ResolvesDevice;

    public function __construct(private readonly TransportBoardingService $engine) {}

    public function verify(DeviceBoardingRequest $request): JsonResponse
    {
        $device = $this->device($request);
        $data = $request->validated();

        $decision = $this->engine->board(new BoardingRequest(
            provider: $device->provider,
            source: BoardingRequest::SOURCE_DEVICE_API,
            method: TransportVerificationMethod::from($data['credential']['type']),
            credential: $data['credential']['value'],
            tripId: $data['trip_id'],
            stopId: $data['stop_id'] ?? null,
            idempotencyKey: $data['idempotency_key'],
            clientRecordedAt: $data['recorded_at'] ?? null,
            device: $device,
        ));

        return response()->json($this->payload($decision), $decision->accepted ? 201 : 422);
    }

    public function sync(DeviceBoardingSyncRequest $request): JsonResponse
    {
        $device = $this->device($request);

        if (! Features::enabled('transport_offline')) {
            return response()->json(['error_code' => 'offline_disabled'], 409);
        }

        $results = [];
        foreach ($request->validated('boardings') as $item) {
            $decision = $this->engine->board(new BoardingRequest(
                provider: $device->provider,
                source: BoardingRequest::SOURCE_OFFLINE_SYNC,
                method: TransportVerificationMethod::from($item['credential']['type']),
                credential: $item['credential']['value'],
                tripId: $item['trip_id'],
                stopId: $item['stop_id'] ?? null,
                idempotencyKey: $item['offline_uuid'],
                clientRecordedAt: $item['recorded_at'],
                device: $device,
                offlineRecordedAt: Carbon::parse($item['recorded_at'])->setTimezone(config('app.timezone')),
            ));
            $results[] = ['offline_uuid' => $item['offline_uuid']] + $this->payload($decision);
        }

        $device->forceFill(['last_sync_at' => now()])->saveQuietly();

        return response()->json([
            'data' => $results,
            'summary' => [
                'received' => count($results),
                'accepted' => count(array_filter($results, fn (array $r): bool => $r['accepted'] && ! $r['replayed'])),
                'replayed' => count(array_filter($results, fn (array $r): bool => $r['replayed'])),
                'refused' => count(array_filter($results, fn (array $r): bool => ! $r['accepted'] && ! $r['replayed'])),
            ],
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(BoardingDecision $decision): array
    {
        $transaction = $decision->transaction;

        return [
            'accepted' => $decision->accepted,
            'replayed' => $decision->replayed,
            'result_code' => $decision->resultCode,
            'message' => $this->engine->message($decision),
            'warnings' => $decision->warnings,
            'boarding_transaction_uuid' => $transaction?->id,
            'boarded_at' => $transaction?->scanned_at?->toIso8601String(),
            'employee' => $transaction?->employee ? $this->employeeDisplay($transaction) : null,
            'trip' => $transaction?->trip ? [
                'id' => $transaction->trip->id,
                'trip_number' => $transaction->trip->trip_number,
                'direction' => $transaction->trip->direction?->value,
                'boarded_count' => $transaction->trip->boarded_count,
                'capacity' => $transaction->trip->effectiveCapacity(),
            ] : null,
            'route' => $transaction?->route ? ['id' => $transaction->route->id, 'route_code' => $transaction->route->route_code, 'name_en' => $transaction->route->name_en, 'name_am' => $transaction->route->name_am] : null,
            'vehicle' => $transaction?->vehicle ? ['id' => $transaction->vehicle->id, 'plate_number' => $transaction->vehicle->plate_number] : null,
            'stop' => $transaction?->stop ? ['id' => $transaction->stop->id, 'stop_code' => $transaction->stop->stop_code, 'name_en' => $transaction->stop->name_en, 'name_am' => $transaction->stop->name_am] : null,
        ];
    }

    /** The operator's confirmation: who boarded, nothing more. @return array<string, mixed> */
    private function employeeDisplay(TransportTransaction $transaction): array
    {
        return [
            'full_name' => $transaction->employee->full_name,
            'employee_number' => $transaction->employee->employee_number,
            // Photo confirmation on the scanner is NEEDS_POLICY_DECISION_PHOTO_CONFIRMATION:
            // when approved it is served through an authenticated route, never a raw URL.
            'photo_url' => null,
        ];
    }
}
