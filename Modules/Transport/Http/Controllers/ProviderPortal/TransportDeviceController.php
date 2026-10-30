<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers\ProviderPortal;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Providers\Contracts\ProviderPortalSession;
use Modules\Transport\Application\Services\TransportDeviceService;
use Modules\Transport\Application\Services\TransportSettings;
use Modules\Transport\Domain\Models\TransportDevice;
use Modules\Transport\Http\Resources\TransportDeviceResource;

/**
 * The scanner devices registered for this provider. Registration and
 * credential rotation are the city's; a provider may report one of its own
 * devices lost and have it revoked at once.
 */
class TransportDeviceController extends Controller
{
    public function index(Request $request, ProviderPortalSession $context, TransportSettings $settings): Response
    {
        $providerId = $context->providerId($request);
        abort_if($providerId === null || ! $context->providerHasService($providerId, 'transport'), 403, __('transport.provider_service_disabled'));

        return Inertia::render('ProviderPortal/Transport/Devices/Index', [
            'devices' => TransportDeviceResource::collection(TransportDevice::query()->with('vehicle')->where('provider_id', $providerId)->orderBy('device_code')->get())->resolve(),
            'inactivityMinutes' => $settings->deviceInactivityTimeoutMinutes(),
        ]);
    }

    public function revoke(Request $request, ProviderPortalSession $context, TransportDevice $device, TransportDeviceService $devices): RedirectResponse
    {
        $providerId = $context->providerId($request);
        abort_if($providerId === null || $device->provider_id !== $providerId, 403, __('transport.own_data_only'));
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $devices->revoke($device, $data['reason'], null, $request, $context->actor($request)?->id);

        return back()->with('flash', ['type' => 'success', 'message' => __('transport.device_revoked')]);
    }
}
