<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsRelationshipTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationUnitRelationshipResource extends JsonResource
{
    use FormatsRelationshipTarget;

    public function toArray(Request $request): array
    {
        $target = $this->target();

        return [
            'id' => $this->id,
            'source_unit_id' => $this->source_unit_id,
            'target_type' => $this->target_type?->value ?? (string) $this->target_type,
            'target_id' => $this->target_id,
            'target' => $this->targetPayload($target),
            'relationship_type' => $this->relationship_type?->value ?? (string) $this->relationship_type,
            'relationship_label' => $this->relationship_type?->label() ?? (string) $this->relationship_type,
            'is_primary' => (bool) $this->is_primary,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'status' => $this->status?->value ?? (string) $this->status,
            'notes_en' => $this->notes_en,
            'notes_am' => $this->notes_am,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
