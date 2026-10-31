<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\DefaultRoleMatrix;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\RbacSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const ROLES = ['Reception Administrator', 'Reception Supervisor', 'Reception Officer', 'Information Officer'];

    public function up(): void
    {
        $rbac = app(RbacSynchronizer::class);
        $rbac->syncCatalog();
        if (Role::query()->where('guard_name', PermissionCatalog::GUARD)->exists()) {
            $definitions = DefaultRoleMatrix::roles();
            foreach (self::ROLES as $name) {
                if (Role::query()->where('name', $name)->where('guard_name', PermissionCatalog::GUARD)->exists()) {
                    continue;
                }
                $attributes = ['name' => $name, 'guard_name' => PermissionCatalog::GUARD, 'scope_type' => $definitions[$name]['scope']];
                if (Schema::hasColumn('roles', 'is_system')) {
                    $attributes += ['description_en' => $definitions[$name]['description_en'], 'description_am' => $definitions[$name]['description_am'], 'is_system' => true];
                }
                Role::query()->create($attributes);
            }
        }
        $rbac->ensureRoles(createMissing: false);
        $rbac->applyMatrix(RbacSynchronizer::MODE_ADDITIVE, onlyExisting: true);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $names = array_column(require database_path('seeders/data/reception-permissions.php'), 'name');
        Permission::query()->whereIn('name', $names)->where('guard_name', PermissionCatalog::GUARD)->delete();
        foreach (self::ROLES as $name) {
            $role = Role::query()->where('name', $name)->where('guard_name', PermissionCatalog::GUARD)->first();
            if ($role !== null && ! DB::table('model_has_roles')->where('role_id', $role->getKey())->exists()) {
                $role->delete();
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
