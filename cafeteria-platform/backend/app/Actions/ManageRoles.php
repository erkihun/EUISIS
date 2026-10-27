<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\CoreRole;
use App\Models\CoreRolePermission;
use App\Models\User;
use App\Services\AuditWriter;
use App\Support\CorePermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Roles and their permissions (ADR 0009), for city-wide holders of
 * roles.manage. Safeguards:
 *  - only catalog permissions; a sensitive one only by a city-wide holder
 *    of roles.manage;
 *  - the Core always keeps an active city-wide account that can administer
 *    accounts and roles, and nobody removes that power from their own role;
 *  - the system roles are never deleted, and no role is deleted while an
 *    account holds it;
 *  - every change is audited with the permissions added and removed.
 */
final readonly class ManageRoles
{
    public function __construct(private AuditWriter $audit) {}

    /** @param array{name: string, scope_type: string, permissions: list<string>, name_am?: ?string} $data */
    public function create(array $data, User $actor): CoreRole
    {
        $this->authorize($actor);
        $permissions = $this->permissions($data['permissions'], $actor);

        return DB::transaction(function () use ($data, $permissions, $actor): CoreRole {
            $key = $this->key($data['name']);
            $role = CoreRole::query()->create(['key' => $key, 'name_en' => $data['name'], 'name_am' => $data['name_am'] ?? null,
                'scope_type' => $data['scope_type'], 'is_system' => false]);
            $role->permissions()->createMany(array_map(fn (string $p): array => ['permission' => $p], $permissions));
            $this->audit->writeFor('role.created', $actor, 'CoreRole', (string) $role->id, null, null, ['key' => $key, 'permissions' => $permissions]);
            CorePermissions::flush();

            return $role;
        });
    }

    /** @param array{name: string, scope_type: string, permissions: list<string>, name_am?: ?string} $data */
    public function update(CoreRole $role, array $data, User $actor): CoreRole
    {
        $this->authorize($actor);
        $permissions = $this->permissions($data['permissions'], $actor);

        return DB::transaction(function () use ($role, $data, $permissions, $actor): CoreRole {
            $role = CoreRole::query()->lockForUpdate()->findOrFail($role->id);
            $before = $role->permissions()->pluck('permission')->all();
            $removed = array_values(array_diff($before, $permissions));
            $added = array_values(array_diff($permissions, $before));
            $administrative = ['users.update', 'roles.manage'];
            if ((string) $actor->role === $role->key && array_intersect($removed, $administrative) !== []) {
                throw ValidationException::withMessages(['permissions' => 'You cannot remove account or role administration from your own role.']);
            }
            if (array_intersect($removed, $administrative) !== [] && ! $this->anotherAdministrator($role->key)) {
                throw ValidationException::withMessages(['permissions' => 'This role holds the last city-wide administration access; give it to another role or account first.']);
            }
            $role->fill(['name_en' => $data['name'], 'name_am' => $data['name_am'] ?? $role->name_am, 'scope_type' => $role->is_system ? $role->scope_type : $data['scope_type']])->save();
            CoreRolePermission::query()->where('core_role_id', $role->id)->whereIn('permission', $removed)->delete();
            $role->permissions()->createMany(array_map(fn (string $p): array => ['permission' => $p], $added));
            $this->audit->writeFor('role.updated', $actor, 'CoreRole', (string) $role->id, null, ['permissions_removed' => $removed], ['permissions_added' => $added, 'name' => $role->name_en]);
            CorePermissions::flush();

            return $role;
        });
    }

    public function delete(CoreRole $role, User $actor): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($role, $actor): void {
            $role = CoreRole::query()->lockForUpdate()->findOrFail($role->id);
            if ($role->is_system) {
                throw ValidationException::withMessages(['role' => 'A system role cannot be deleted.']);
            }
            if (User::query()->where('role', $role->key)->exists()) {
                throw ValidationException::withMessages(['role' => 'Accounts still hold this role; give them another role first.']);
            }
            $role->delete();
            $this->audit->writeFor('role.deleted', $actor, 'CoreRole', (string) $role->id, null, ['key' => $role->key, 'name' => $role->name_en]);
            CorePermissions::flush();
        });
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->is_active && $actor->isCityWide() && $actor->can('roles.manage'), 403, 'Roles are managed by a city-wide administrator.');
    }

    /**
     * @param  list<string>  $requested
     * @return list<string>
     */
    private function permissions(array $requested, User $actor): array
    {
        $requested = array_values(array_unique($requested));
        $unknown = array_diff($requested, CorePermissions::all());
        if ($unknown !== []) {
            throw ValidationException::withMessages(['permissions' => 'Unknown permission: '.implode(', ', $unknown).'.']);
        }
        if (array_intersect($requested, CorePermissions::SENSITIVE) !== [] && ! ($actor->isCityWide() && $actor->can('roles.manage'))) {
            throw ValidationException::withMessages(['permissions' => 'Only a city-wide administrator may grant sensitive permissions.']);
        }

        return $requested;
    }

    /** Another active city-wide account (outside this role) can still administer accounts and roles. */
    private function anotherAdministrator(string $roleKey): bool
    {
        $roles = array_values(array_diff(SaveUser::administratorRoles(), [$roleKey]));

        return $roles !== [] && User::query()->whereIn('role', $roles)->where('scope_type', User::SCOPE_CITYWIDE)->where('is_active', true)->exists();
    }

    private function key(string $name): string
    {
        $base = Str::of($name)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(40, '')->toString() ?: 'role';
        $key = $base;
        for ($n = 2; CoreRole::query()->where('key', $key)->exists(); $n++) {
            $key = $base.'_'.$n;
        }

        return $key;
    }
}
