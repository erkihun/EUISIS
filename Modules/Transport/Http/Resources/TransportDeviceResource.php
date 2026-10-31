<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransportDeviceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->provider_id,
            'transport_vehicle_id' => $this->transport_vehicle_id,
            'device_code' => $this->device_code,
            'name' => $this->name,
            'status' => $this->status?->value,
            'credential_prefix' => $this->credential_prefix,
            'platform' => $this->platform,
            'software_version' => $this->software_version,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'last_sync_at' => $this->last_sync_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revocation_reason' => $this->revocation_reason,
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle ? ['id' => $this->vehicle->id, 'vehicle_code' => $this->vehicle->vehicle_code, 'plate_number' => $this->vehicle->plate_number] : null),
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? ['id' => $this->provider->id, 'provider_code' => $this->provider->provider_code, 'name_en' => $this->provider->name_en, 'name_am' => $this->provider->name_am] : null),
        ];
    }
}
