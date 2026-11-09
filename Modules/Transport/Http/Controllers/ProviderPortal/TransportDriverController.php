<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers\ProviderPortal;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Providers\Contracts\ProviderPortalSession;
use Modules\Transport\Domain\Models\TransportDriver;
use Modules\Transport\Domain\Models\TransportVehicle;
use Modules\Transport\Http\Requests\StoreTransportDriverRequest;
use Modules\Transport\Http\Requests\UpdateTransportDriverRequest;
use Modules\Transport\Http\Resources\TransportDriverResource;
use Modules\Transport\Http\Resources\TransportVehicleResource;

class TransportDriverController extends Controller
{
    public function index(Request $request, ProviderPortalSession $context): Response
    {
        $providerId = $this->providerId($request, $context);

        return Inertia::render('ProviderPortal/Transport/Drivers/Index', [
            'drivers' => TransportDriverResource::collection(TransportDriver::query()->with('vehicle')->where('provider_id', $providerId)->orderBy('full_name')->get())->resolve(),
        ]);
    }

    public function create(Request $request, ProviderPortalSession $context): Response
    {
        return Inertia::render('ProviderPortal/Transport/Drivers/Create', $this->formPayload($request, $context));
    }

    public function store(StoreTransportDriverRequest $request, ProviderPortalSession $context): RedirectResponse
    {
        $providerId = $this->providerId($request, $context);
        TransportDriver::query()->create(array_merge($request->validated(), ['provider_id' => $providerId]));

        return to_route('provider.portal.transport.drivers.index')->with('flash', ['type' => 'success', 'message' => __('transport.driver_created')]);
    }

    public function edit(Request $request, ProviderPortalSession $context, TransportDriver $driver): Response
    {
        abort_if($driver->provider_id !== $this->providerId($request, $context), 403, __('transport.own_data_only'));

        return Inertia::render('ProviderPortal/Transport/Drivers/Edit', [
            'driver' => (new TransportDriverResource($driver->load('vehicle')))->resolve(),
            ...$this->formPayload($request, $context),
        ]);
    }

    public function update(UpdateTransportDriverRequest $request, ProviderPortalSession $context, TransportDriver $driver): RedirectResponse
    {
        abort_if($driver->provider_id !== $this->providerId($request, $context), 403, __('transport.own_data_only'));
        $driver->update($request->validated());

        return to_route('provider.portal.transport.drivers.index')->with('flash', ['type' => 'success', 'message' => __('transport.driver_updated')]);
    }

    /** @return array<string, mixed> */
    private function formPayload(Request $request, ProviderPortalSession $context): array
    {
        $providerId = $this->providerId($request, $context);

        return [
            'vehicles' => TransportVehicleResource::collection(TransportVehicle::query()->where('provider_id', $providerId)->orderBy('vehicle_code')->get())->resolve(),
        ];
    }

    /** The signed-in portal user's provider, as an id, when it runs transport. */
    private function providerId(Request $request, ProviderPortalSession $context): string
    {
        $providerId = $context->providerId($request);
        abort_if($providerId === null || ! $context->providerHasService($providerId, 'transport'), 403, __('transport.provider_service_disabled'));

        return $providerId;
    }
}
