<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Permission */
class PermissionResource extends JsonResource
{
    /** Which kind of role holds the permission: scoped, global, both, or none. */
    public static function scopeOf(int $globalRoles, int $scopedRoles): string
    {
        return match (true) {
            $globalRoles > 0 && $scopedRoles > 0 => 'both',
            $scopedRoles > 0 => 'scoped',
            $globalRoles > 0 => 'global',
            default => 'unassigned',
        };
    }

    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'guard_name' => $this->guard_name,
            'label_en' => $this->label_en,
            'label_am' => $this->label_am,
            'description_en' => $this->description_en,
            'description_am' => $this->description_am,
            'group' => $this->group,
            'sort_order' => $this->sort_order,
            'is_system' => (bool) $this->is_system,
            'roles_count' => $this->whenCounted('roles'),
            'scope' => $this->when(
                isset($this->global_roles_count, $this->scoped_roles_count),
                fn (): string => self::scopeOf((int) $this->global_roles_count, (int) $this->scoped_roles_count),
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            // Backend policies stay the source of truth; these flags only
            // decide which action buttons the UI renders.
            'can' => [
                'view' => $user?->can('view', $this->resource) ?? false,
                'update' => $user?->can('update', $this->resource) ?? false,
                // System permissions are never deletable — checked here in
                // addition to the policy because Gate::before (Super Admin)
                // bypasses policy methods, and the controller enforces the
                // same rule server-side.
                'delete' => ! $this->is_system && ($user?->can('delete', $this->resource) ?? false),
            ],
        ];
    }
}
