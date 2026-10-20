<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal\Transport;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProviderPortal\ProviderTransportScanRequest;
use App\Http\Resources\TransportRouteResource;
use App\Http\Resources\TransportRouteStopResource;
use App\Http\Resources\TransportTransactionResource;
use App\Http\Resources\TransportTripResource;
use App\Models\ProviderUser;
use App\Models\TransportRoute;
use App\Models\TransportRouteStop;
use App\Models\TransportTrip;
use App\Services\ProviderPortal\ProviderPortalContext;
use App\Services\Transport\TransportQrScanService;
use App\Services\Transport\TransportSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransportScanController extends Controller
{
    public function index(Request $request, ProviderPortalContext $context, TransportSettings $settings): Response
    {
        $provider = $context->provider($request);
        abort_if($provider === null || ! $provider->hasService('transport'), 403, __('transport.provider_service_disabled'));

        return Inertia::render('ProviderPortal/Transport/Scan', [
            'routes' => TransportRouteResource::collection(TransportRoute::query()->where('provider_id', $provider->id)->where('is_active', true)->orderBy('name_en')->get())->resolve(),
            'trips' => TransportTripResource::collection(TransportTrip::query()->with(['route', 'vehicle'])->where('provider_id', $provider->id)->whereDate('trip_date', today())->whereNotIn('status', ['completed', 'cancelled', 'failed'])->orderBy('departure_time')->get())->resolve(),
            'stops' => TransportRouteStopResource::collection(TransportRouteStop::query()->whereIn('transport_route_id', TransportRoute::query()->where('provider_id', $provider->id)->select('id'))->where('is_active', true)->orderBy('sequence')->get())->resolve(),
            'terminal' => [
                'manualAllowed' => auth('provider')->user()?->canUseServicePermission('provider.transport.manual_exception') && $settings->manualExceptionEnabled(),
                'canDispatch' => auth('provider')->user()?->canUseServicePermission('provider.transport.dispatch') ?? false,
                'tripRequired' => $settings->requireTripForBoarding(),
                'nfcEnabled' => $settings->nfcEnabled(),
            ],
        ]);
    }

    public function store(ProviderTransportScanRequest $request, TransportQrScanService $scanService): JsonResponse
    {
        /** @var ProviderUser $providerUser */
        $providerUser = auth('provider')->user();
        $result = $scanService->process($providerUser, $request->validated());

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
