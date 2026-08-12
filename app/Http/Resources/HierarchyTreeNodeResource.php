<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HierarchyTreeNodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // Unified node fields (new)
            'id' => $this['id'] ?? $this['organization_id'],
            'type' => $this['type'] ?? 'organization',
            'label' => $this['label'] ?? $this['name_en'],
            'node_type_label' => $this['node_type_label'] ?? null,
            'node_type_label_am' => $this['node_type_label_am'] ?? null,
            'status_label' => $this['status_label'] ?? null,
            'meta' => $this['meta'] ?? null,
            // Legacy fields kept for backwards compatibility
            'organization_id' => $this['organization_id'],
            'edge_id' => $this['edge_id'],
            'parent_organization_id' => $this['parent_organization_id'],
            'code' => $this['code'],
            'name_en' => $this['name_en'],
            'name_am' => $this['name_am'],
            'organization_type' => $this['organization_type'],
            'status' => $this['status'],
            'logo_url' => $this['logo_url'],
            'depth' => $this['depth'],
            'child_count' => $this['child_count'],
            'relationship_type' => $this['relationship_type'],
            'effective_from' => $this['effective_from'],
            'effective_to' => $this['effective_to'],
            'can' => $this['can'],
            'children' => collect($this['children'] ?? [])
                ->map(fn (array $child): array => (new self($child))->toArray($request))
                ->values()
                ->all(),
        ];
    }
}
