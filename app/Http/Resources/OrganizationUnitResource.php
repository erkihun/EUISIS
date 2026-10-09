<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\OrganizationUnitStatus;
use App\Models\OrganizationUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationUnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'organization' => $this->whenLoaded('organization', fn () => [
                'id' => $this->organization->id,
                'name_en' => $this->organization->name_en,
                'name_am' => $this->organization->name_am,
                'code' => $this->organization->code,
            ]),
            'parent_unit_id' => $this->parent_unit_id,
            'parent' => $this->whenLoaded('parent', fn () => $this->parent ? [
                'id' => $this->parent->id,
                'name_en' => $this->parent->name_en,
                'name_am' => $this->parent->name_am,
                'code' => $this->parent->code,
            ] : null),
            'organization_unit_type_id' => $this->organization_unit_type_id,
            'unitType' => $this->whenLoaded('unitType', fn () => $this->unitType ? [
                'id' => $this->unitType->id,
                'code' => $this->unitType->code,
                'name_en' => $this->unitType->name_en,
                'name_am' => $this->unitType->name_am,
            ] : null),
            'unit_type' => $this->unit_type,
            'code' => $this->code,
            'name_en' => $this->name_en,
            'name_am' => $this->name_am,
            'description_en' => $this->description_en,
            'description_am' => $this->description_am,
            'status' => $this->status instanceof OrganizationUnitStatus
                ? $this->status->value
                : $this->status,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'sort_order' => $this->sort_order,
            'children_count' => $this->whenCounted('children'),
            'children' => $this->whenLoaded('children', fn () => self::collection($this->children)),
            'can' => [
                'update' => $user?->can('update', $this->resource) ?? false,
                'archive' => $user?->can('archive', $this->resource) ?? false,
                'restore' => $user?->can('restore', $this->resource) ?? false,
                'manageHierarchy' => $user?->can('manageHierarchy', OrganizationUnit::class) ?? false,
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
