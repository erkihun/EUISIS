<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

readonly class AssignableUserRoleService
{
    /** @return Collection<int, Role> */
    public function rolesFor(User $actor): Collection
    {
        $query = Role::query()->orderBy('name');

        if (! $this->canAssignGlobalRoles($actor)) {
            $query
                ->where('scope_type', 'scoped')
                ->whereNotIn('name', $this->protectedGlobalRoleNames());
        }

        return $query->get(['id', 'name', 'scope_type']);
    }

    /** @param array<int, int|string> $roleIds */
    public function roleNamesForIds(array $roleIds): array
    {
        return Role::query()
            ->whereIn('id', $roleIds)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /** @param array<int, string> $roleNames */
    public function roleIdsForNames(array $roleNames): array
    {
        return Role::query()
            ->whereIn('name', $roleNames)
            ->pluck('id')
            ->all();
    }

    /** @param array<int, string> $roleNames */
    public function firstUnassignableRole(User $actor, array $roleNames): ?string
    {
        return Role::query()
            ->whereIn('name', $roleNames)
            ->get()
            ->first(fn (Role $role): bool => ! $role->canBeAssignedBy($actor))
            ?->name;
    }

    /** @return array<int, string> */
    public function organizationScopedRoleNames(): array
    {
        return Role::query()->where('scope_type', 'scoped')->pluck('name')->all();
    }

    public function isOrganizationScoped(Role|string $role): bool
    {
        $name = $role instanceof Role ? $role->name : $role;

        if ($role instanceof Role) {
            return $role->isScoped() && ! $role->isProtected();
        }

        return Role::query()
            ->where('name', $name)
            ->where('scope_type', 'scoped')
            ->whereNotIn('name', $this->protectedGlobalRoleNames())
            ->exists();
    }

    /** @return array<int, string> */
    public function globalRoleNames(): array
    {
        return Role::query()->where('scope_type', 'global')->pluck('name')->all();
    }

    public function canAssignGlobalRoles(User $actor): bool
    {
        return $actor->hasAnyRole(['Super Admin', 'System Admin']);
    }

    /** @return array<int, string> */
    private function protectedGlobalRoleNames(): array
    {
        return [
            'Super Admin',
            'System Admin',
            'City Admin',
            'Public Service Bureau Admin',
            'Security Settings Manager',
        ];
    }
}
