<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal\Transport;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProviderPortal\ProviderTransportScanRequest;
use App\Http\Resources\TransportRouteResource;
use App\Http\Resources\TransportTransactionResource;
use App\Http\Resources\TransportTripResource;
use App\Models\ProviderUser;
use App\Models\TransportRoute;
use App\Models\TransportTrip;
use App\Services\ProviderPortal\ProviderPortalContext;
use App\Services\Transport\TransportQrScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransportScanController extends Controller
{
    public function index(Request $request, ProviderPortalContext $context): Response
    {
        $provider = $context->provider($request);
        abort_if($provider === null || ! $provider->hasService('transport'), 403, __('transport.provider_service_disabled'));

        return Inertia::render('ProviderPortal/Transport/Scan', [
            'routes' => TransportRouteResource::collection(TransportRoute::query()->where('provider_id', $provider->id)->where('is_active', true)->orderBy('name_en')->get())->resolve(),
            'trips' => TransportTripResource::collection(TransportTrip::query()->with('route')->where('provider_id', $provider->id)->whereDate('trip_date', today())->orderBy('departure_time')->get())->resolve(),
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
            'message' => __($result['message_key']),
            'transaction' => $result['transaction'] ? (new TransportTransactionResource($result['transaction']))->resolve() : null,
        ], $result['accepted'] ? 201 : 422);
    }
}
