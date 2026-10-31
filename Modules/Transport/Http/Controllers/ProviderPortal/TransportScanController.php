<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers\ProviderPortal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Providers\Contracts\ProviderPortalSession;
use Modules\Transport\Application\Services\TransportQrScanService;
use Modules\Transport\Application\Services\TransportSettings;
use Modules\Transport\Domain\Models\TransportRoute;
use Modules\Transport\Domain\Models\TransportRouteStop;
use Modules\Transport\Domain\Models\TransportTransaction;
use Modules\Transport\Domain\Models\TransportTrip;
use Modules\Transport\Http\Requests\ProviderPortal\ProviderTransportScanRequest;
use Modules\Transport\Http\Resources\TransportRouteResource;
use Modules\Transport\Http\Resources\TransportRouteStopResource;
use Modules\Transport\Http\Resources\TransportTransactionResource;
use Modules\Transport\Http\Resources\TransportTripResource;

class TransportScanController extends Controller
{
    public function index(Request $request, ProviderPortalSession $context, TransportSettings $settings): Response
    {
        $providerId = $context->providerId($request);
        abort_if($providerId === null || ! $context->providerHasService($providerId, 'transport'), 403, __('transport.provider_service_disabled'));

        return Inertia::render('ProviderPortal/Transport/Scan', [
            'routes' => TransportRouteResource::collection(TransportRoute::query()->where('provider_id', $providerId)->where('is_active', true)->orderBy('name_en')->get())->resolve(),
            'trips' => TransportTripResource::collection(TransportTrip::query()->with(['route', 'vehicle'])->where('provider_id', $providerId)->whereDate('trip_date', today())->whereNotIn('status', ['completed', 'cancelled', 'failed'])->orderBy('departure_time')->get())->resolve(),
            'stops' => TransportRouteStopResource::collection(TransportRouteStop::query()->whereIn('transport_route_id', TransportRoute::query()->where('provider_id', $providerId)->select('id'))->where('is_active', true)->orderBy('sequence')->get())->resolve(),
            'terminal' => [
                'manualAllowed' => $context->can($request, 'provider.transport.manual_exception') && $settings->manualExceptionEnabled(),
                'canDispatch' => $context->can($request, 'provider.transport.dispatch'),
                'tripRequired' => $settings->requireTripForBoarding(),
                'nfcEnabled' => $settings->nfcEnabled(),
            ],
        ]);
    }

    /** Today's boardings at this provider, newest first, for the terminal's list; a trip narrows it. */
    public function today(Request $request, ProviderPortalSession $context): JsonResponse
    {
        $providerId = $context->providerId($request);
        abort_if($providerId === null || ! $context->providerHasService($providerId, 'transport'), 403, __('transport.provider_service_disabled'));
        $data = $request->validate(['transport_trip_id' => ['nullable', 'uuid']]);

        return response()->json([
            'data' => TransportTransactionResource::collection(
                TransportTransaction::query()
                    ->with(['employee.currentAssignment.organization', 'route', 'trip'])
                    ->where('provider_id', $providerId)
                    ->whereDate('transaction_date', today())
                    ->when($data['transport_trip_id'] ?? null, fn ($query, $trip) => $query->where('transport_trip_id', $trip))
                    ->orderByDesc('scanned_at')
                    ->limit(200)
                    ->get()
            )->resolve(),
        ]);
    }

    public function store(ProviderTransportScanRequest $request, TransportQrScanService $scanService, ProviderPortalSession $context): JsonResponse
    {
        $actor = $context->actor($request);
        abort_if($actor === null, 403);
        $result = $scanService->process($actor, $request->validated());

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
