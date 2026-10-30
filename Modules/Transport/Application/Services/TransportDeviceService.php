<?php

declare(strict_types=1);

namespace Modules\Transport\Application\Services;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Providers\Domain\Models\Provider;
use Modules\Transport\Domain\Enums\TransportDeviceStatus;
use Modules\Transport\Domain\Models\TransportDevice;

/**
 * Scanner device registry. A device gets its own bearer credential at
 * registration (shown once, stored hashed); the city or the provider can
 * revoke it on its own, without touching any other device or the provider's
 * portal accounts.
 */
class TransportDeviceService
{
    public function __construct(private readonly WriteAuditLogAction $audit) {}

    /**
     * @param  array{device_code: string, name: string, transport_vehicle_id?: string|null, platform?: string|null, software_version?: string|null}  $data
     * @return array{device: TransportDevice, credential: string} the plain credential is returned once and never stored
     */
    public function register(Provider $provider, array $data, ?User $actor, ?Request $request = null): array
    {
        $plain = TransportDevice::CREDENTIAL_PREFIX.Str::random(40);

        $device = TransportDevice::query()->create([
            'provider_id' => $provider->id,
            'transport_vehicle_id' => $data['transport_vehicle_id'] ?? null,
            'device_code' => $data['device_code'],
            'name' => $data['name'],
            'status' => TransportDeviceStatus::Active,
            'credential_hash' => TransportDevice::hashCredential($plain),
            'credential_prefix' => substr($plain, 0, 12),
            'platform' => $data['platform'] ?? null,
            'software_version' => $data['software_version'] ?? null,
            'registered_by' => $actor?->id,
        ]);

        $this->audit->execute(AuditEventType::TransportDeviceRegistered, $actor, $device, null,
            newValues: ['provider_id' => $provider->id, 'device_code' => $device->device_code, 'transport_vehicle_id' => $device->transport_vehicle_id], request: $request);

        return ['device' => $device, 'credential' => $plain];
    }

    /** A new credential for the same device; the old one stops working at once. @return array{device: TransportDevice, credential: string} */
    public function rotate(TransportDevice $device, ?User $actor, ?Request $request = null): array
    {
        $plain = TransportDevice::CREDENTIAL_PREFIX.Str::random(40);
        $device->forceFill(['credential_hash' => TransportDevice::hashCredential($plain), 'credential_prefix' => substr($plain, 0, 12)])->save();
        $this->forgetResolved($device);

        $this->audit->execute(AuditEventType::TransportDeviceRegistered, $actor, $device, null, newValues: ['rotated' => true, 'device_code' => $device->device_code], request: $request);

        return ['device' => $device, 'credential' => $plain];
    }

    public function revoke(TransportDevice $device, string $reason, ?User $actor, ?Request $request = null, ?string $providerUserId = null): TransportDevice
    {
        $device->forceFill([
            'status' => TransportDeviceStatus::Revoked,
            'revoked_at' => now(),
            'revoked_by' => $actor?->id,
            'revocation_reason' => $reason,
        ])->save();
        $this->forgetResolved($device);

        $this->audit->execute(AuditEventType::TransportDeviceRevoked, $actor, $device, null,
            newValues: ['device_code' => $device->device_code, 'provider_user_id' => $providerUserId], reason: $reason, request: $request);

        return $device;
    }

    public function setStatus(TransportDevice $device, TransportDeviceStatus $status, ?User $actor, ?Request $request = null): TransportDevice
    {
        if ($device->status === TransportDeviceStatus::Revoked) {
            return $device;
        }
        $device->forceFill(['status' => $status])->save();
        $this->forgetResolved($device);

        return $device;
    }

    /** The device a bearer credential names, when it is live and its provider still offers transport. */
    public function resolve(string $plain): ?TransportDevice
    {
        if (! str_starts_with($plain, TransportDevice::CREDENTIAL_PREFIX) || strlen($plain) > 80) {
            return null;
        }

        $device = TransportDevice::query()->with(['provider', 'vehicle'])->where('credential_hash', TransportDevice::hashCredential($plain))->first();
        if ($device === null || ! $device->isActive() || $device->provider === null || $device->provider->status !== 'active' || ! $device->provider->hasService('transport')) {
            return null;
        }

        return $device;
    }

    /** Last-seen bookkeeping, at most once a minute per device so a boarding burst costs no extra writes. */
    public function touch(TransportDevice $device, ?string $ip, ?string $softwareVersion = null): void
    {
        $key = 'transport:device-seen:'.$device->id;
        if (Cache::has($key)) {
            return;
        }
        Cache::put($key, true, 60);

        $device->forceFill(array_filter([
            'last_seen_at' => now(),
            'last_ip' => $ip,
            'software_version' => $softwareVersion,
        ], fn ($v) => $v !== null))->saveQuietly();
    }

    private function forgetResolved(TransportDevice $device): void
    {
        Cache::forget('transport:device-seen:'.$device->id);
    }
}
