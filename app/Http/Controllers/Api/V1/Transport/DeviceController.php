<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Transport;

use App\Http\Controllers\Api\V1\Transport\Concerns\ResolvesDevice;
use App\Http\Controllers\Controller;
use App\Services\Transport\TransportSettings;
use App\Support\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What a device needs to know about itself and the switches that apply to it. */
class DeviceController extends Controller
{
    use ResolvesDevice;

    public function show(Request $request, TransportSettings $settings): JsonResponse
    {
        $device = $this->device($request);

        return response()->json([
            'device' => [
                'id' => $device->id,
                'device_code' => $device->device_code,
                'name' => $device->name,
                'status' => $device->status->value,
                'provider' => ['id' => $device->provider->id, 'provider_code' => $device->provider->provider_code, 'name_en' => $device->provider->name_en, 'name_am' => $device->provider->name_am],
                'vehicle' => $device->vehicle ? ['id' => $device->vehicle->id, 'plate_number' => $device->vehicle->plate_number, 'capacity' => $device->vehicle->approvedCapacity()] : null,
            ],
            'capabilities' => [
                'qr' => $settings->qrEnabled(),
                'nfc' => $settings->nfcEnabled() && Features::enabled('transport_nfc'),
                'offline' => $settings->offlineEnabled() && Features::enabled('transport_offline'),
                'offline_grace_minutes' => $settings->offlineGraceMinutes(),
                'gps' => Features::enabled('transport_gps'),
                'nonce_required' => true,
            ],
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $device->forceFill(['last_sync_at' => now()])->saveQuietly();

        return response()->json(['ok' => true, 'server_time' => now()->toIso8601String()]);
    }
}
