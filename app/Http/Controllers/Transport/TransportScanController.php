<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransportScanRequest;
use App\Http\Resources\TransportRouteResource;
use App\Http\Resources\TransportTransactionResource;
use App\Http\Resources\TransportTripResource;
use App\Models\Provider;
use App\Models\TransportRoute;
use App\Models\TransportTrip;
use App\Services\Transport\TransportQrScanService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class TransportScanController extends Controller
{
    public function index(): Response
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
                    ->with('route')
                    ->whereDate('trip_date', today())
                    ->orderBy('departure_time')
                    ->get()
            )->resolve(),
        ]);
    }

    public function store(TransportScanRequest $request, TransportQrScanService $scanService): JsonResponse
    {
        $data = $request->validated();
        $provider = Provider::query()->findOrFail($data['provider_id']);
        $result = $scanService->processForAdmin($provider, $data);

        return response()->json([
            'accepted' => $result['accepted'],
            'result_code' => $result['result_code'],
            'message' => __($result['message_key']),
            'transaction' => $result['transaction'] ? (new TransportTransactionResource($result['transaction']))->resolve() : null,
        ], $result['accepted'] ? 201 : 422);
    }
}
