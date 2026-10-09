<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VerificationResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'allowed' => $this['allowed'],
            'result_code' => $this['result_code'],
            'denial_reason' => $this['denial_reason'] ?? null,
            'card_status' => $this['card_status'] ?? null,
            'employee_status' => $this['employee_status'] ?? null,
            'service_type' => $this['service_type'] ?? null,
            'quota_remaining' => $this['quota_remaining'] ?? null,
        ];
    }
}
