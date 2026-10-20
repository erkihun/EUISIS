<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransportPassResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'provider_id' => $this->provider_id,
            'transport_route_id' => $this->transport_route_id,
            'boarding_stop_id' => $this->boarding_stop_id,
            'evening_stop_id' => $this->evening_stop_id,
            'suspended_reason' => $this->suspended_reason,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'status' => $this->status,
            'notes' => $this->notes,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee?->id,
                'employee_number' => $this->employee?->employee_number,
                'full_name' => $this->employee?->full_name,
            ]),
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? [
                'id' => $this->provider->id,
                'provider_code' => $this->provider->provider_code,
                'name_en' => $this->provider->name_en,
                'name_am' => $this->provider->name_am,
            ] : null),
            'route' => $this->whenLoaded('route', fn () => $this->route ? [
                'id' => $this->route->id,
                'name_en' => $this->route->name_en,
                'name_am' => $this->route->name_am,
                'route_code' => $this->route->route_code,
            ] : null),
            'boarding_stop' => $this->whenLoaded('boardingStop', fn () => $this->boardingStop ? ['id' => $this->boardingStop->id, 'stop_code' => $this->boardingStop->stop_code, 'name_en' => $this->boardingStop->name_en, 'name_am' => $this->boardingStop->name_am] : null),
            'evening_stop' => $this->whenLoaded('eveningStop', fn () => $this->eveningStop ? ['id' => $this->eveningStop->id, 'stop_code' => $this->eveningStop->stop_code, 'name_en' => $this->eveningStop->name_en, 'name_am' => $this->eveningStop->name_am] : null),
        ];
    }
}
