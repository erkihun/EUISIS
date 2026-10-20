<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Transport\TransportDeviceService;
use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a registered scanner device by its own bearer credential
 * (`Authorization: Bearer tdev_…`). No shared API key: each device is
 * revocable on its own, and its provider and vehicle binding travel with
 * the request as the only source of authority for the boarding engine.
 */
class AuthenticateTransportDevice
{
    public const ATTRIBUTE = 'transport_device';

    public function __construct(private readonly TransportDeviceService $devices) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! Features::enabled('transport')) {
            return response()->json(['error_code' => 'module_disabled'], 404);
        }

        $token = $request->bearerToken();
        $device = $token === null ? null : $this->devices->resolve($token);

        if ($device === null) {
            return response()->json(['error_code' => 'device_unauthorized'], 401, ['WWW-Authenticate' => 'Bearer realm="transport-device"']);
        }

        $request->attributes->set(self::ATTRIBUTE, $device);
        $this->devices->touch($device, $request->ip(), $request->header('X-Device-Software'));

        return $next($request);
    }
}
