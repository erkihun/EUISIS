<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransportProviderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_code' => $this->provider_code,
            'name_en' => $this->name_en,
            'name_am' => $this->name_am,
            'assigned_scope_type' => $this->assigned_scope_type,
            'status' => $this->status,
            'contact_person' => $this->contact_person,
            'email' => $this->email,
            'assigned_organization' => $this->whenLoaded('assignedOrganization', fn () => [
                'id' => $this->assignedOrganization?->id,
                'name_en' => $this->assignedOrganization?->name_en,
                'name_am' => $this->assignedOrganization?->name_am,
            ]),
            'profile' => $this->whenLoaded('transportProfile', fn () => [
                'license_number' => $this->transportProfile?->license_number,
                'registration_number' => $this->transportProfile?->registration_number,
                'service_area_description_en' => $this->transportProfile?->service_area_description_en,
                'service_area_description_am' => $this->transportProfile?->service_area_description_am,
                'status' => $this->transportProfile?->status,
            ]),
        ];
    }
}
