<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OccupationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'isco_code' => $this->isco_code,
            'is_active' => $this->deleted_at === null,
            'name_en' => $this->name_en,
            'name_am' => $this->name_am,
            'skill_specialization' => $this->skill_specialization,
            'skill_specialization_am' => $this->skill_specialization_am,
            'skill_level' => $this->skill_level,
            'description' => $this->description,
            'description_en' => $this->description_en ?? $this->description,
            'description_am' => $this->description_am,
            'isco_major_group_code' => $this->isco_major_group_code,
            'isco_sub_major_group_code' => $this->isco_sub_major_group_code,
            'isco_minor_group_code' => $this->isco_minor_group_code,
            'isco_unit_group_code' => $this->isco_unit_group_code,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
            'can' => [
                'view' => $user?->can('view', $this->resource) ?? false,
                'update' => $user?->can('update', $this->resource) ?? false,
                'archive' => $user?->can('archive', $this->resource) ?? false,
                'restore' => $user?->can('restore', $this->resource) ?? false,
            ],
        ];
    }
}
