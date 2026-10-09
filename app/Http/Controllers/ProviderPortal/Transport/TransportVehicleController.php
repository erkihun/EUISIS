<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal\Transport;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransportVehicleRequest;
use App\Http\Requests\UpdateTransportVehicleRequest;
use App\Http\Resources\TransportRouteResource;
use App\Http\Resources\TransportVehicleResource;
use App\Models\Provider;
use App\Models\TransportRoute;
use App\Models\TransportVehicle;
use App\Services\ProviderPortal\ProviderPortalContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransportVehicleController extends Controller
{
    public function index(Request $request, ProviderPortalContext $context): Response
    {
        $provider = $this->provider($request, $context);

        return Inertia::render('ProviderPortal/Transport/Vehicles/Index', [
            'vehicles' => TransportVehicleResource::collection(TransportVehicle::query()->with('route')->where('provider_id', $provider->id)->orderBy('vehicle_code')->get())->resolve(),
        ]);
    }

    public function create(Request $request, ProviderPortalContext $context): Response
    {
        $provider = $this->provider($request, $context);

        return Inertia::render('ProviderPortal/Transport/Vehicles/Create', [
            'routes' => TransportRouteResource::collection(TransportRoute::query()->where('provider_id', $provider->id)->orderBy('name_en')->get())->resolve(),
        ]);
    }

    public function store(StoreTransportVehicleRequest $request, ProviderPortalContext $context): RedirectResponse
    {
        $provider = $this->provider($request, $context);
        TransportVehicle::query()->create(array_merge($request->validated(), ['provider_id' => $provider->id]));

        return to_route('provider.portal.transport.vehicles.index')->with('flash', ['type' => 'success', 'message' => __('transport.vehicle_created')]);
    }

    public function edit(Request $request, ProviderPortalContext $context, TransportVehicle $vehicle): Response
    {
        $provider = $this->provider($request, $context);
        abort_if($vehicle->provider_id !== $provider->id, 403, __('transport.own_data_only'));

        return Inertia::render('ProviderPortal/Transport/Vehicles/Edit', [
            'vehicle' => (new TransportVehicleResource($vehicle->load('route')))->resolve(),
            'routes' => TransportRouteResource::collection(TransportRoute::query()->where('provider_id', $provider->id)->orderBy('name_en')->get())->resolve(),
        ]);
    }

    public function update(UpdateTransportVehicleRequest $request, ProviderPortalContext $context, TransportVehicle $vehicle): RedirectResponse
    {
        abort_if($vehicle->provider_id !== $this->provider($request, $context)->id, 403, __('transport.own_data_only'));
        $vehicle->update($request->validated());

        return to_route('provider.portal.transport.vehicles.index')->with('flash', ['type' => 'success', 'message' => __('transport.vehicle_updated')]);
    }

    private function provider(Request $request, ProviderPortalContext $context): Provider
    {
        $provider = $context->provider($request);
        abort_if($provider === null || ! $provider->hasService('transport'), 403, __('transport.provider_service_disabled'));

        return $provider;
    }
}
