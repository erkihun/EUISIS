<?php

declare(strict_types=1);

use App\Models\Role;
use App\Support\Rbac\DefaultRoleMatrix;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\RbacSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/*
 * Creates the City-wide Viewer role on installed systems (fresh installs get
 * it from RoleSeeder) and grants it the look-only set of the role matrix.
 *
 * Additive only: nothing is removed from any existing role, and the role is
 * given to nobody.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rbac = app(RbacSynchronizer::class);
        $rbac->syncCatalog();

        $name = DefaultRoleMatrix::CITYWIDE_VIEWER;
        $installed = Role::query()->where('guard_name', PermissionCatalog::GUARD)->exists();

        if ($installed && ! Role::query()->where('name', $name)->where('guard_name', PermissionCatalog::GUARD)->exists()) {
            $definition = DefaultRoleMatrix::roles()[$name];
            $attributes = ['name' => $name, 'guard_name' => PermissionCatalog::GUARD, 'scope_type' => $definition['scope']];
            if (Schema::hasColumn('roles', 'is_system')) {
                $attributes += ['description_en' => $definition['description_en'], 'description_am' => $definition['description_am'], 'is_system' => true];
            }
            if (Schema::hasColumn('roles', 'name_am')) {
                $attributes += ['name_am' => DefaultRoleMatrix::nameAm($name)];
            }
            Role::query()->create($attributes);
        }

        $rbac->ensureRoles(createMissing: false);
        $rbac->applyMatrix(RbacSynchronizer::MODE_ADDITIVE, onlyExisting: true);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // The role is kept: removing it would silently strip access people were given.
    }
};
