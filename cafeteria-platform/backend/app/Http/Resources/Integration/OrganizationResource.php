<?php

declare(strict_types=1);

namespace App\Http\Resources\Integration;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** An organization as integrations see it; parent_id is its parent in the hierarchy in force. */
class OrganizationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name_en' => $this->name_en,
            'name_am' => $this->name_am,
            'status' => $this->status,
            'organization_type' => $this->type?->code,
            'parent_id' => $this->getAttribute('parent_id'),
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
