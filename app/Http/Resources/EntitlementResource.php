<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EntitlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_type_id' => $this->service_type_id,
            'service_provider_id' => $this->service_provider_id,
            'status' => $this->status->value,
            'quota_limit' => $this->quota_limit,
            'quota_used' => $this->quota_used,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
        ];
    }
}
