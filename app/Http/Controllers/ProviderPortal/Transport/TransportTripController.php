<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal\Transport;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransportTripRequest;
use App\Http\Requests\UpdateTransportTripRequest;
use App\Http\Resources\TransportDriverResource;
use App\Http\Resources\TransportRouteResource;
use App\Http\Resources\TransportTripResource;
use App\Http\Resources\TransportVehicleResource;
use App\Models\Provider;
use App\Models\TransportDriver;
use App\Models\TransportRoute;
use App\Models\TransportTrip;
use App\Models\TransportVehicle;
use App\Services\ProviderPortal\ProviderPortalContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransportTripController extends Controller
{
    public function index(Request $request, ProviderPortalContext $context): Response
    {
        $provider = $this->provider($request, $context);

        return Inertia::render('ProviderPortal/Transport/Trips/Index', [
            'trips' => TransportTripResource::collection(TransportTrip::query()->with(['route', 'vehicle', 'driver'])->where('provider_id', $provider->id)->latest('trip_date')->get())->resolve(),
        ]);
    }

    public function create(Request $request, ProviderPortalContext $context): Response
    {
        return Inertia::render('ProviderPortal/Transport/Trips/Create', $this->formPayload($request, $context));
    }

    public function store(StoreTransportTripRequest $request, ProviderPortalContext $context): RedirectResponse
    {
        $provider = $this->provider($request, $context);
        $data = $request->validated();
        $data['trip_number'] ??= 'TRP-TRIP-'.now()->format('Ymd').'-'.str_pad((string) (TransportTrip::query()->whereDate('created_at', today())->count() + 1), 5, '0', STR_PAD_LEFT);

        TransportTrip::query()->create(array_merge($data, ['provider_id' => $provider->id]));

        return to_route('provider.portal.transport.trips.index')->with('flash', ['type' => 'success', 'message' => __('transport.trip_created')]);
    }

    public function edit(Request $request, ProviderPortalContext $context, TransportTrip $trip): Response
    {
        abort_if($trip->provider_id !== $this->provider($request, $context)->id, 403, __('transport.own_data_only'));

        return Inertia::render('ProviderPortal/Transport/Trips/Edit', [
            'trip' => (new TransportTripResource($trip->load(['route', 'vehicle', 'driver'])))->resolve(),
            ...$this->formPayload($request, $context),
        ]);
    }

    public function update(UpdateTransportTripRequest $request, ProviderPortalContext $context, TransportTrip $trip): RedirectResponse
    {
        abort_if($trip->provider_id !== $this->provider($request, $context)->id, 403, __('transport.own_data_only'));
        $trip->update($request->validated());

        return to_route('provider.portal.transport.trips.index')->with('flash', ['type' => 'success', 'message' => __('transport.trip_updated')]);
    }

    /** @return array<string, mixed> */
    private function formPayload(Request $request, ProviderPortalContext $context): array
    {
        $provider = $this->provider($request, $context);

        return [
            'routes' => TransportRouteResource::collection(TransportRoute::query()->where('provider_id', $provider->id)->orderBy('name_en')->get())->resolve(),
            'vehicles' => TransportVehicleResource::collection(TransportVehicle::query()->where('provider_id', $provider->id)->orderBy('vehicle_code')->get())->resolve(),
            'drivers' => TransportDriverResource::collection(TransportDriver::query()->where('provider_id', $provider->id)->orderBy('full_name')->get())->resolve(),
        ];
    }

    private function provider(Request $request, ProviderPortalContext $context): Provider
    {
        $provider = $context->provider($request);
        abort_if($provider === null || ! $provider->hasService('transport'), 403, __('transport.provider_service_disabled'));

        return $provider;
    }
}
