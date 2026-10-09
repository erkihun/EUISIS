<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EntitlementRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'service_type_id' => $this->service_type_id,
            'service_type' => $this->serviceType ? [
                'id' => $this->serviceType->id,
                'name_en' => $this->serviceType->name_en,
                'name_am' => $this->serviceType->name_am,
            ] : null,
            'rule_definition' => $this->rule_definition,
            'is_active' => $this->is_active,
            'entitlements_count' => $this->whenCounted('entitlements'),
            'can' => [
                'view' => $user?->can('view', $this->resource) ?? false,
                'update' => $user?->can('update', $this->resource) ?? false,
                'archive' => $user?->can('archive', $this->resource) ?? false,
                'restore' => $user?->can('restore', $this->resource) ?? false,
            ],
        ];
    }
}
