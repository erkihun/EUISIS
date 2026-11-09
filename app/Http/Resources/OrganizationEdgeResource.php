<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OrganizationEdge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrganizationEdge */
class OrganizationEdgeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hierarchy_version_id' => $this->hierarchy_version_id,
            'parent_organization_id' => $this->parent_organization_id,
            'child_organization_id' => $this->child_organization_id,
            'relationship_type' => $this->relationship_type?->value ?? (string) $this->relationship_type,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'parent_organization' => $this->whenLoaded('parentOrganization', fn () => $this->parentOrganization ? [
                'id' => $this->parentOrganization->id,
                'code' => $this->parentOrganization->code,
                'name_en' => $this->parentOrganization->name_en,
                'name_am' => $this->parentOrganization->name_am,
                'status' => $this->parentOrganization->status?->value ?? (string) $this->parentOrganization->status,
                'logo_url' => $this->parentOrganization->logo_url,
                'type' => $this->parentOrganization->type ? [
                    'code' => $this->parentOrganization->type->code,
                    'name_en' => $this->parentOrganization->type->name_en,
                    'name_am' => $this->parentOrganization->type->name_am,
                ] : null,
            ] : null),
            'child_organization' => $this->whenLoaded('childOrganization', fn () => $this->childOrganization ? [
                'id' => $this->childOrganization->id,
                'code' => $this->childOrganization->code,
                'name_en' => $this->childOrganization->name_en,
                'name_am' => $this->childOrganization->name_am,
                'status' => $this->childOrganization->status?->value ?? (string) $this->childOrganization->status,
                'logo_url' => $this->childOrganization->logo_url,
                'type' => $this->childOrganization->type ? [
                    'code' => $this->childOrganization->type->code,
                    'name_en' => $this->childOrganization->type->name_en,
                    'name_am' => $this->childOrganization->type->name_am,
                ] : null,
            ] : null),
            'can' => [
                'view' => $request->user()?->can('view', $this->resource) ?? false,
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'remove' => $request->user()?->can('delete', $this->resource) ?? false,
            ],
        ];
    }
}
