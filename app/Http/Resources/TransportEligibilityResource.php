<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransportEligibilityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject_type' => $this->subject_type?->value,
            'employee_id' => $this->employee_id,
            'organization_id' => $this->organization_id,
            'scope_type' => $this->scope_type,
            'provider_id' => $this->provider_id,
            'transport_contract_id' => $this->transport_contract_id,
            'status' => $this->status?->value,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'authorization_reference' => $this->authorization_reference,
            'notes' => $this->notes,
            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? ['id' => $this->employee->id, 'employee_number' => $this->employee->employee_number, 'full_name' => $this->employee->full_name] : null),
            'organization' => $this->whenLoaded('organization', fn () => $this->organization ? ['id' => $this->organization->id, 'code' => $this->organization->code, 'name_en' => $this->organization->name_en, 'name_am' => $this->organization->name_am] : null),
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? ['id' => $this->provider->id, 'provider_code' => $this->provider->provider_code, 'name_en' => $this->provider->name_en, 'name_am' => $this->provider->name_am] : null),
            'authorized_by' => $this->whenLoaded('authorizedBy', fn () => $this->authorizedBy?->name),
        ];
    }
}
