<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Applies PermissionCatalog and DefaultRoleMatrix to the database.
 *
 * Safe on an existing database: it only creates or updates. It never deletes
 * a permission or a role, never touches a role that is not in the matrix
 * (custom roles), and never changes user assignments.
 */
final class RbacSynchronizer
{
    /** Keep system roles exactly equal to the matrix (adds and removes). */
    public const MODE_SYNC = 'sync';

    /** Only add missing matrix permissions; never remove anything. */
    public const MODE_ADDITIVE = 'additive';

    private const PERMISSION_COLUMNS = ['group', 'sort_order', 'is_system', 'label_en', 'label_am', 'description_en', 'description_am'];

    /** @return array{created: int, updated: int} */
    public function syncCatalog(): array
    {
        $created = 0;
        $updated = 0;
        $existing = Permission::query()->where('guard_name', PermissionCatalog::GUARD)->get()->keyBy('name');

        DB::transaction(function () use ($existing, &$created, &$updated): void {
            $now = now();
            $rows = [];
            foreach (PermissionCatalog::all() as $name => $entry) {
                $attributes = array_intersect_key($entry, array_flip(self::PERMISSION_COLUMNS)) + ['sort_order' => 0, 'is_system' => false];
                $permission = $existing->get($name);
                if ($permission === null) {
                    // Bulk-inserted below: this also runs inside every test migration.
                    $rows[] = [
                        'name' => $name, 'guard_name' => PermissionCatalog::GUARD, 'created_at' => $now, 'updated_at' => $now,
                        'group' => $attributes['group'] ?? null, 'sort_order' => $attributes['sort_order'], 'is_system' => (bool) $attributes['is_system'],
                        'label_en' => $attributes['label_en'] ?? null, 'label_am' => $attributes['label_am'] ?? null,
                        'description_en' => $attributes['description_en'] ?? null, 'description_am' => $attributes['description_am'] ?? null,
                    ];

                    continue;
                }
                $permission->fill($attributes);
                if ($permission->isDirty()) {
                    $permission->save();
                    $updated++;
                }
            }
            foreach (array_chunk($rows, 50) as $chunk) {
                Permission::query()->insert($chunk);
            }
            $created = count($rows);
        });

        $this->forget();

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * Create (optionally) and describe the system roles. Existing roles keep
     * their users; only name-keyed metadata is updated.
     *
     * @return array{created: list<string>, updated: list<string>}
     */
    public function ensureRoles(bool $createMissing = true): array
    {
        $created = [];
        $updated = [];
        $hasMetadata = Schema::hasColumn('roles', 'is_system');
        $existing = Role::query()->where('guard_name', PermissionCatalog::GUARD)->whereIn('name', DefaultRoleMatrix::names())->get()->keyBy('name');

        foreach (DefaultRoleMatrix::roles() as $name => $definition) {
            $role = $existing->get($name);
            if ($role === null && ! $createMissing) {
                continue;
            }
            $attributes = ['scope_type' => $definition['scope']];
            if ($hasMetadata) {
                $attributes += ['description_en' => $definition['description_en'], 'description_am' => $definition['description_am'], 'is_system' => true];
            }
            if ($role === null) {
                Role::query()->create(['name' => $name, 'guard_name' => PermissionCatalog::GUARD, ...$attributes]);
                $created[] = $name;

                continue;
            }
            $role->forceFill($attributes);
            if ($role->isDirty()) {
                $role->save();
                $updated[] = $name;
            }
        }

        $this->forget();

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * Grant the matrix to the system roles.
     *
     * @return array<string, array{added: list<string>, removed: list<string>}>
     */
    public function applyMatrix(string $mode = self::MODE_SYNC, bool $onlyExisting = true): array
    {
        if (! in_array($mode, [self::MODE_SYNC, self::MODE_ADDITIVE], true)) {
            throw new InvalidArgumentException("Unknown RBAC sync mode: {$mode}");
        }

        $report = [];
        $ids = Permission::query()->where('guard_name', PermissionCatalog::GUARD)->pluck('id', 'name');
        $existing = Role::query()->where('guard_name', PermissionCatalog::GUARD)->whereIn('name', DefaultRoleMatrix::names())->with('permissions:id,name')->get()->keyBy('name');
        $pivot = config('permission.table_names.role_has_permissions') ?? 'role_has_permissions';
        $roleKey = config('permission.column_names.role_pivot_key') ?? 'role_id';
        $permissionKey = config('permission.column_names.permission_pivot_key') ?? 'permission_id';

        DB::transaction(function () use ($existing, $ids, $mode, $onlyExisting, $pivot, $roleKey, $permissionKey, &$report): void {
            foreach (DefaultRoleMatrix::roles() as $name => $definition) {
                $role = $existing->get($name);
                if ($role === null) {
                    if ($onlyExisting) {
                        continue;
                    }
                    throw new InvalidArgumentException("Role '{$name}' does not exist; run RoleSeeder first.");
                }

                $missingInDb = array_diff($definition['permissions'], $ids->keys()->all());
                if ($missingInDb !== []) {
                    throw new InvalidArgumentException('Run PermissionSeeder first; missing: '.implode(', ', $missingInDb));
                }

                // A pivot diff by id (not syncPermissions): one pass per role, same result.
                $current = $role->permissions->pluck('name')->all();
                $added = array_values(array_diff($definition['permissions'], $current));
                $removed = $mode === self::MODE_SYNC ? array_values(array_diff($current, $definition['permissions'])) : [];

                foreach (array_chunk($added, 200) as $chunk) {
                    DB::table($pivot)->insert(array_map(fn (string $permission): array => [$roleKey => $role->getKey(), $permissionKey => $ids[$permission]], $chunk));
                }
                if ($removed !== []) {
                    DB::table($pivot)->where($roleKey, $role->getKey())
                        ->whereIn($permissionKey, $role->permissions->whereIn('name', $removed)->modelKeys())->delete();
                }

                if ($added !== [] || $removed !== []) {
                    $report[$name] = ['added' => $added, 'removed' => $removed];
                }
            }
        });

        $this->forget();

        return $report;
    }

    private function forget(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
