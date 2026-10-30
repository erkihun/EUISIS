<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Providers\Domain\Models\Provider;
use Modules\Transport\Application\Services\TransportQrScanService;
use Modules\Transport\Application\Services\TransportSettings;
use Modules\Transport\Domain\Models\TransportRoute;
use Modules\Transport\Domain\Models\TransportRouteStop;
use Modules\Transport\Domain\Models\TransportTransaction;
use Modules\Transport\Domain\Models\TransportTrip;
use Modules\Transport\Http\Requests\TransportScanRequest;
use Modules\Transport\Http\Resources\TransportRouteResource;
use Modules\Transport\Http\Resources\TransportRouteStopResource;
use Modules\Transport\Http\Resources\TransportTransactionResource;
use Modules\Transport\Http\Resources\TransportTripResource;

class TransportScanController extends Controller
{
    public function index(Request $request, TransportSettings $settings): Response
    {
        return Inertia::render('Transport/Scan', [
            'providers' => Provider::query()
                ->whereHas('services.serviceType', fn ($query) => $query->where('code', 'transport'))
                ->where('status', 'active')
                ->orderBy('name_en')
                ->get(['id', 'provider_code', 'name_en', 'name_am']),
            'routes' => TransportRouteResource::collection(
                TransportRoute::query()
                    ->where('is_active', true)
                    ->orderBy('name_en')
                    ->get()
            )->resolve(),
            'trips' => TransportTripResource::collection(
                TransportTrip::query()
                    ->with(['route', 'vehicle'])
                    ->whereDate('trip_date', today())
                    ->whereNotIn('status', ['completed', 'cancelled', 'failed'])
                    ->orderBy('departure_time')
                    ->get()
            )->resolve(),
            'stops' => TransportRouteStopResource::collection(TransportRouteStop::query()->where('is_active', true)->orderBy('sequence')->get())->resolve(),
            'terminal' => [
                'manualAllowed' => ($request->user()?->can('transport-scan.manual') ?? false) && $settings->manualExceptionEnabled(),
                'canDispatch' => $request->user()?->can('transport-trips.dispatch') ?? false,
                'tripRequired' => $settings->requireTripForBoarding(),
                'nfcEnabled' => $settings->nfcEnabled(),
            ],
        ]);
    }

    /**
     * Today's boardings at one provider, newest first, for the terminal's
     * list (an admin terminal names the provider; the trip narrows it).
     */
    public function today(Request $request): JsonResponse
    {
        // Validated first: PostgreSQL rejects comparing a uuid key with '' or other text.
        $data = $request->validate([
            'provider_id' => ['required', 'uuid', 'exists:providers,id'],
            'transport_trip_id' => ['nullable', 'uuid'],
        ]);

        return response()->json([
            'data' => TransportTransactionResource::collection(
                TransportTransaction::query()
                    ->with(['employee.currentAssignment.organization', 'route', 'trip'])
                    ->where('provider_id', $data['provider_id'])
                    ->whereDate('transaction_date', today())
                    ->when($data['transport_trip_id'] ?? null, fn ($query, $trip) => $query->where('transport_trip_id', $trip))
                    ->orderByDesc('scanned_at')
                    ->limit(200)
                    ->get()
            )->resolve(),
        ]);
    }

    public function store(TransportScanRequest $request, TransportQrScanService $scanService): JsonResponse
    {
        $data = $request->validated();
        $provider = Provider::query()->findOrFail($data['provider_id']);
        $result = $scanService->processForAdmin($provider, $data, $request->user());

        return response()->json([
            'accepted' => $result['accepted'],
            'result_code' => $result['result_code'],
            'message' => $scanService->message($result),
            'warnings' => $result['warnings'] ?? [],
            'transaction' => $result['transaction'] ? (new TransportTransactionResource($result['transaction']->loadMissing(['employee.currentAssignment.organization', 'route', 'trip'])))->resolve() : null,
            'trip' => $result['transaction']?->trip ? ['id' => $result['transaction']->trip->id, 'boarded_count' => $result['transaction']->trip->boarded_count, 'effective_capacity' => $result['transaction']->trip->effectiveCapacity(), 'status' => $result['transaction']->trip->status?->value] : null,
        ], $result['accepted'] ? 201 : 422);
    }
}
