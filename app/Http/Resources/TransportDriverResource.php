<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransportDriverResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->provider_id,
            'full_name' => $this->full_name,
            'license_number' => $this->license_number,
            'license_expiry_date' => $this->license_expiry_date?->toDateString(),
            'status' => $this->status,
            'assigned_vehicle_id' => $this->assigned_vehicle_id,
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle ? [
                'id' => $this->vehicle->id,
                'vehicle_code' => $this->vehicle->vehicle_code,
                'plate_number' => $this->vehicle->plate_number,
            ] : null),
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? [
                'id' => $this->provider->id,
                'provider_code' => $this->provider->provider_code,
                'name_en' => $this->provider->name_en,
            ] : null),
        ];
    }
}
