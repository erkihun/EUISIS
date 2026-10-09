<?php

declare(strict_types=1);

namespace App\Http\Resources\Transfers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransferRuleOverrideResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rule_key' => $this->rule_key,
            'reason' => $this->reason,
            'status' => $this->status?->value,
            'decided_at' => $this->decided_at?->toISOString(),
            'requested_by' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy ? [
                'id' => $this->requestedBy->id,
                'name' => $this->requestedBy->name,
            ] : null),
            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy ? [
                'id' => $this->approvedBy->id,
                'name' => $this->approvedBy->name,
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
