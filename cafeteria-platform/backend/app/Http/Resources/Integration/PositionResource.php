<?php

declare(strict_types=1);

namespace App\Http\Resources\Integration;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A position (a seat in an organization), independent of whoever holds it. */
class PositionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->job_position_code,
            'title_en' => $this->title_en,
            'title_am' => $this->title_am,
            'organization_id' => $this->organization_id,
            'organization_unit_id' => $this->organization_unit_id,
            'grade_level' => $this->grade_level,
            'is_active' => (bool) $this->is_active,
            'occupied' => (int) ($this->holders_count ?? 0) > 0,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
