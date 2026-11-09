<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransportInvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'provider_id' => $this->provider_id,
            'transport_contract_id' => $this->transport_contract_id,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'claimed_boardings' => $this->claimed_boardings,
            'claimed_trips' => $this->claimed_trips,
            'claimed_employee_days' => $this->claimed_employee_days,
            'claimed_amount' => $this->claimed_amount !== null ? (string) $this->claimed_amount : null,
            'currency_code' => $this->currency_code,
            'status' => $this->status?->value,
            'has_attachment' => $this->attachment_path !== null,
            'notes' => $this->notes,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'returned_reason' => $this->returned_reason,
            'transport_settlement_id' => $this->transport_settlement_id,
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? ['id' => $this->provider->id, 'provider_code' => $this->provider->provider_code, 'name_en' => $this->provider->name_en, 'name_am' => $this->provider->name_am] : null),
            'contract' => $this->whenLoaded('contract', fn () => $this->contract ? ['id' => $this->contract->id, 'contract_reference' => $this->contract->contract_reference, 'version_no' => $this->contract->version_no, 'settlement_model' => $this->contract->settlement_model?->value] : null),
            'settlement' => $this->whenLoaded('settlement', fn () => $this->settlement ? ['id' => $this->settlement->id, 'settlement_number' => $this->settlement->settlement_number, 'status' => $this->settlement->status?->value] : null),
        ];
    }
}
