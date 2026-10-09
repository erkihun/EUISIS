<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal\Transport;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransportDriverRequest;
use App\Http\Requests\UpdateTransportDriverRequest;
use App\Http\Resources\TransportDriverResource;
use App\Http\Resources\TransportVehicleResource;
use App\Models\Provider;
use App\Models\TransportDriver;
use App\Models\TransportVehicle;
use App\Services\ProviderPortal\ProviderPortalContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransportDriverController extends Controller
{
    public function index(Request $request, ProviderPortalContext $context): Response
    {
        $provider = $this->provider($request, $context);

        return Inertia::render('ProviderPortal/Transport/Drivers/Index', [
            'drivers' => TransportDriverResource::collection(TransportDriver::query()->with('vehicle')->where('provider_id', $provider->id)->orderBy('full_name')->get())->resolve(),
        ]);
    }

    public function create(Request $request, ProviderPortalContext $context): Response
    {
        return Inertia::render('ProviderPortal/Transport/Drivers/Create', $this->formPayload($request, $context));
    }

    public function store(StoreTransportDriverRequest $request, ProviderPortalContext $context): RedirectResponse
    {
        $provider = $this->provider($request, $context);
        TransportDriver::query()->create(array_merge($request->validated(), ['provider_id' => $provider->id]));

        return to_route('provider.portal.transport.drivers.index')->with('flash', ['type' => 'success', 'message' => __('transport.driver_created')]);
    }

    public function edit(Request $request, ProviderPortalContext $context, TransportDriver $driver): Response
    {
        abort_if($driver->provider_id !== $this->provider($request, $context)->id, 403, __('transport.own_data_only'));

        return Inertia::render('ProviderPortal/Transport/Drivers/Edit', [
            'driver' => (new TransportDriverResource($driver->load('vehicle')))->resolve(),
            ...$this->formPayload($request, $context),
        ]);
    }

    public function update(UpdateTransportDriverRequest $request, ProviderPortalContext $context, TransportDriver $driver): RedirectResponse
    {
        abort_if($driver->provider_id !== $this->provider($request, $context)->id, 403, __('transport.own_data_only'));
        $driver->update($request->validated());

        return to_route('provider.portal.transport.drivers.index')->with('flash', ['type' => 'success', 'message' => __('transport.driver_updated')]);
    }

    /** @return array<string, mixed> */
    private function formPayload(Request $request, ProviderPortalContext $context): array
    {
        $provider = $this->provider($request, $context);

        return [
            'vehicles' => TransportVehicleResource::collection(TransportVehicle::query()->where('provider_id', $provider->id)->orderBy('vehicle_code')->get())->resolve(),
        ];
    }

    private function provider(Request $request, ProviderPortalContext $context): Provider
    {
        $provider = $context->provider($request);
        abort_if($provider === null || ! $provider->hasService('transport'), 403, __('transport.provider_service_disabled'));

        return $provider;
    }
}
