<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CodeGenerationLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity_type' => $this->entity_type?->value ?? $this->entity_type,
            'entity_id' => $this->entity_id,
            'generated_code' => $this->generated_code,
            'sequence_number' => $this->sequence_number,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'generated_by' => $this->generator?->only(['id', 'name']),
            'metadata' => $this->metadata,
        ];
    }
}
