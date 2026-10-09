<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\UserOrganizationScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserOrganizationScope */
class UserOrganizationScopeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization' => $this->organization ? [
                'id' => $this->organization->id,
                'name_en' => $this->organization->name_en,
                'name_am' => $this->organization->name_am,
            ] : null,
            'scope_type' => $this->scope_type?->value ?? $this->scope_type,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'is_active' => $this->is_active,
            'assigned_by' => $this->assigned_by,
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
