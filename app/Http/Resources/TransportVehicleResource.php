<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransportVehicleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->provider_id,
            'vehicle_code' => $this->vehicle_code,
            'plate_number' => $this->plate_number,
            'vehicle_type' => $this->vehicle_type,
            'capacity' => $this->capacity,
            'status' => $this->status,
            'assigned_route_id' => $this->assigned_route_id,
            'route' => $this->whenLoaded('route', fn () => $this->route ? [
                'id' => $this->route->id,
                'name_en' => $this->route->name_en,
                'route_code' => $this->route->route_code,
            ] : null),
            'model' => $this->model,
            'year' => $this->year,
            'color' => $this->color,
        ];
    }
}
