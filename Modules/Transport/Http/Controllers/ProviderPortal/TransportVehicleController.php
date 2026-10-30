<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers\ProviderPortal;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Providers\Contracts\ProviderPortalSession;
use Modules\Transport\Domain\Models\TransportRoute;
use Modules\Transport\Domain\Models\TransportVehicle;
use Modules\Transport\Http\Requests\StoreTransportVehicleRequest;
use Modules\Transport\Http\Requests\UpdateTransportVehicleRequest;
use Modules\Transport\Http\Resources\TransportRouteResource;
use Modules\Transport\Http\Resources\TransportVehicleResource;

class TransportVehicleController extends Controller
{
    public function index(Request $request, ProviderPortalSession $context): Response
    {
        $providerId = $this->providerId($request, $context);

        return Inertia::render('ProviderPortal/Transport/Vehicles/Index', [
            'vehicles' => TransportVehicleResource::collection(TransportVehicle::query()->with('route')->where('provider_id', $providerId)->orderBy('vehicle_code')->get())->resolve(),
        ]);
    }

    public function create(Request $request, ProviderPortalSession $context): Response
    {
        $providerId = $this->providerId($request, $context);

        return Inertia::render('ProviderPortal/Transport/Vehicles/Create', [
            'routes' => TransportRouteResource::collection(TransportRoute::query()->where('provider_id', $providerId)->orderBy('name_en')->get())->resolve(),
        ]);
    }

    public function store(StoreTransportVehicleRequest $request, ProviderPortalSession $context): RedirectResponse
    {
        $providerId = $this->providerId($request, $context);
        TransportVehicle::query()->create(array_merge($request->validated(), ['provider_id' => $providerId]));

        return to_route('provider.portal.transport.vehicles.index')->with('flash', ['type' => 'success', 'message' => __('transport.vehicle_created')]);
    }

    public function edit(Request $request, ProviderPortalSession $context, TransportVehicle $vehicle): Response
    {
        $providerId = $this->providerId($request, $context);
        abort_if($vehicle->provider_id !== $providerId, 403, __('transport.own_data_only'));

        return Inertia::render('ProviderPortal/Transport/Vehicles/Edit', [
            'vehicle' => (new TransportVehicleResource($vehicle->load('route')))->resolve(),
            'routes' => TransportRouteResource::collection(TransportRoute::query()->where('provider_id', $providerId)->orderBy('name_en')->get())->resolve(),
        ]);
    }

    public function update(UpdateTransportVehicleRequest $request, ProviderPortalSession $context, TransportVehicle $vehicle): RedirectResponse
    {
        abort_if($vehicle->provider_id !== $this->providerId($request, $context), 403, __('transport.own_data_only'));
        $vehicle->update($request->validated());

        return to_route('provider.portal.transport.vehicles.index')->with('flash', ['type' => 'success', 'message' => __('transport.vehicle_updated')]);
    }

    /** The signed-in portal user's provider, as an id, when it runs transport. */
    private function providerId(Request $request, ProviderPortalSession $context): string
    {
        $providerId = $context->providerId($request);
        abort_if($providerId === null || ! $context->providerHasService($providerId, 'transport'), 403, __('transport.provider_service_disabled'));

        return $providerId;
    }
}
