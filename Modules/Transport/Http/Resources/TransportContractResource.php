<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransportContractResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_reference' => $this->contract_reference,
            'organization_id' => $this->organization_id,
            'provider_id' => $this->provider_id,
            'version_no' => $this->version_no,
            'supersedes_contract_id' => $this->supersedes_contract_id,
            'status' => $this->status?->value,
            'currency_code' => $this->currency_code,
            'settlement_model' => $this->settlement_model?->value,
            'settlement_period' => $this->settlement_period?->value,
            'terms' => $this->terms ?? [],
            'missing_terms' => $this->missingTerms(),
            'service_coverage' => $this->service_coverage,
            'terms_reference' => $this->terms_reference,
            'has_agreement' => $this->agreement_path !== null,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? ['id' => $this->provider->id, 'provider_code' => $this->provider->provider_code, 'name_en' => $this->provider->name_en, 'name_am' => $this->provider->name_am] : null),
            'organization' => $this->whenLoaded('organization', fn () => $this->organization ? ['id' => $this->organization->id, 'code' => $this->organization->code, 'name_en' => $this->organization->name_en, 'name_am' => $this->organization->name_am] : null),
        ];
    }
}
