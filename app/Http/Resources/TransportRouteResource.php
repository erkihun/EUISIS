<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransportRouteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->provider_id,
            'route_code' => $this->route_code,
            'name_en' => $this->name_en,
            'name_am' => $this->name_am,
            'origin_en' => $this->origin_en,
            'origin_am' => $this->origin_am,
            'destination_en' => $this->destination_en,
            'destination_am' => $this->destination_am,
            'distance_km' => $this->distance_km,
            'estimated_duration_minutes' => $this->estimated_duration_minutes,
            'assigned_organization_id' => $this->assigned_organization_id,
            'assigned_scope_type' => $this->assigned_scope_type,
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'assigned_organization' => $this->whenLoaded('assignedOrganization', fn () => $this->assignedOrganization ? [
                'id' => $this->assignedOrganization->id,
                'code' => $this->assignedOrganization->code,
                'name_en' => $this->assignedOrganization->name_en,
                'name_am' => $this->assignedOrganization->name_am,
            ] : null),
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? [
                'id' => $this->provider->id,
                'provider_code' => $this->provider->provider_code,
                'name_en' => $this->provider->name_en,
                'name_am' => $this->provider->name_am,
            ] : null),
        ];
    }
}
