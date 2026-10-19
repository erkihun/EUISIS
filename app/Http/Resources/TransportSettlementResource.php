<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransportSettlementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'settlement_number' => $this->settlement_number,
            'provider_id' => $this->provider_id,
            'transport_contract_id' => $this->transport_contract_id,
            'transport_invoice_id' => $this->transport_invoice_id,
            'organization_id' => $this->organization_id,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'status' => $this->status?->value,
            'contract_snapshot' => $this->contract_snapshot,
            'basis' => $this->basis,
            'verified_boardings' => $this->verified_boardings,
            'unique_riders' => $this->unique_riders,
            'employee_days' => $this->employee_days,
            'completed_trips' => $this->completed_trips,
            'cancelled_trips' => $this->cancelled_trips,
            'currency_code' => $this->currency_code,
            'calculated_amount' => $this->calculated_amount !== null ? (string) $this->calculated_amount : null,
            'claimed_amount' => $this->claimed_amount !== null ? (string) $this->claimed_amount : null,
            'adjustment_total' => (string) $this->adjustment_total,
            'approved_amount' => $this->approved_amount !== null ? (string) $this->approved_amount : null,
            'computed_by' => $this->computed_by,
            'computed_at' => $this->computed_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_reason' => $this->rejected_reason,
            'payment_reference' => $this->payment_reference,
            'payment_marked_at' => $this->payment_marked_at?->toIso8601String(),
            'notes' => $this->notes,
            'policy_gaps' => $this->policy_gaps ?? [],
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? ['id' => $this->provider->id, 'provider_code' => $this->provider->provider_code, 'name_en' => $this->provider->name_en, 'name_am' => $this->provider->name_am] : null),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? (new TransportInvoiceResource($this->invoice))->resolve() : null),
            'computed_by_name' => $this->whenLoaded('computedBy', fn () => $this->computedBy?->name),
            'reviewed_by_name' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy?->name),
            'approved_by_name' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy?->name),
            'adjustments' => $this->whenLoaded('adjustments', fn () => $this->adjustments->map(fn ($a): array => [
                'id' => $a->id,
                'origin' => $a->origin?->value,
                'kind' => $a->kind,
                'dispute_type' => $a->dispute_type,
                'amount' => (string) $a->amount,
                'quantity' => $a->quantity,
                'reason' => $a->reason,
                'status' => $a->status?->value,
                'requested_by_user_id' => $a->requested_by_user_id,
                'decided_at' => $a->decided_at?->toIso8601String(),
                'decision_notes' => $a->decision_notes,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->all()),
        ];
    }
}
