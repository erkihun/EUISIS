<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransportRouteStopResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transport_route_id' => $this->transport_route_id,
            'stop_code' => $this->stop_code,
            'name_en' => $this->name_en,
            'name_am' => $this->name_am,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'sequence' => $this->sequence,
            'pickup_allowed' => (bool) $this->pickup_allowed,
            'dropoff_allowed' => (bool) $this->dropoff_allowed,
            'is_active' => (bool) $this->is_active,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
        ];
    }
}
