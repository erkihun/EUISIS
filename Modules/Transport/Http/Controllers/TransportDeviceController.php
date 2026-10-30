<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Providers\Domain\Models\Provider;
use Modules\Transport\Application\Services\TransportDeviceService;
use Modules\Transport\Application\Services\TransportSettings;
use Modules\Transport\Domain\Enums\TransportDeviceStatus;
use Modules\Transport\Domain\Models\TransportDevice;
use Modules\Transport\Domain\Models\TransportVehicle;
use Modules\Transport\Http\Requests\StoreTransportDeviceRequest;
use Modules\Transport\Http\Resources\TransportDeviceResource;
use Modules\Transport\Http\Resources\TransportVehicleResource;

/** Scanner devices: registered by the city, revocable one at a time; the credential is shown once. */
class TransportDeviceController extends Controller
{
    public function index(Request $request, TransportSettings $settings): Response
    {
        $user = $request->user();
        abort_unless($user?->can('transport-devices.viewAny'), 403);

        $devices = TransportDevice::query()->with(['provider', 'vehicle'])
            ->when($request->provider_id, fn ($q, $id) => $q->where('provider_id', $id))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('device_code')->paginate(25)->withQueryString();

        return Inertia::render('Transport/Devices/Index', [
            'devices' => TransportDeviceResource::collection($devices)->response()->getData(true),
            'filters' => $request->only('provider_id', 'status'),
            'providers' => $this->providers(),
            'inactivityMinutes' => $settings->deviceInactivityTimeoutMinutes(),
            'credential' => session('transport_device_credential'),
            'can' => ['create' => $user->can('transport-devices.create'), 'revoke' => $user->can('transport-devices.revoke'), 'update' => $user->can('transport-devices.update')],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('transport-devices.create'), 403);

        return Inertia::render('Transport/Devices/Create', [
            'providers' => $this->providers(),
            'vehicles' => TransportVehicleResource::collection(TransportVehicle::query()->where('status', 'active')->orderBy('vehicle_code')->get())->resolve(),
        ]);
    }

    public function store(StoreTransportDeviceRequest $request, TransportDeviceService $devices): RedirectResponse
    {
        $provider = Provider::query()->findOrFail($request->validated('provider_id'));
        $result = $devices->register($provider, $request->validated(), $request->user(), $request);

        // Shown once, on the next page only; never stored in plain text.
        return to_route('transport.devices.index')->with('transport_device_credential', ['device_code' => $result['device']->device_code, 'credential' => $result['credential']])
            ->with('flash', ['type' => 'success', 'message' => __('transport.device_registered')]);
    }

    public function revoke(Request $request, TransportDevice $device, TransportDeviceService $devices): RedirectResponse
    {
        abort_unless($request->user()?->can('transport-devices.revoke'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $devices->revoke($device, $data['reason'], $request->user(), $request);

        return back()->with('flash', ['type' => 'success', 'message' => __('transport.device_revoked')]);
    }

    public function rotate(Request $request, TransportDevice $device, TransportDeviceService $devices): RedirectResponse
    {
        abort_unless($request->user()?->can('transport-devices.update'), 403);
        abort_if($device->status === TransportDeviceStatus::Revoked, 422, __('transport.device_is_revoked'));
        $result = $devices->rotate($device, $request->user(), $request);

        return back()->with('transport_device_credential', ['device_code' => $device->device_code, 'credential' => $result['credential']])
            ->with('flash', ['type' => 'success', 'message' => __('transport.device_rotated')]);
    }

    public function setStatus(Request $request, TransportDevice $device, TransportDeviceService $devices): RedirectResponse
    {
        abort_unless($request->user()?->can('transport-devices.update'), 403);
        $data = $request->validate(['status' => ['required', 'in:active,suspended']]);
        $devices->setStatus($device, TransportDeviceStatus::from($data['status']), $request->user(), $request);

        return back()->with('flash', ['type' => 'success', 'message' => __('transport.device_updated')]);
    }

    private function providers()
    {
        return Provider::query()->whereHas('services.serviceType', fn ($q) => $q->where('code', 'transport'))->orderBy('name_en')->get(['id', 'provider_code', 'name_en', 'name_am']);
    }
}
