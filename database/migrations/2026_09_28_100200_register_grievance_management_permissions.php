<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Support\Grievances\GrievanceRoles;
use App\Support\Rbac\DefaultRoleMatrix;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\RbacSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/*
 * Registers the Grievance Management permissions, creates the new grievance
 * roles on installed systems (fresh installs get them from RoleSeeder), and
 * grants every holder of a first-module permission its new equivalents so
 * custom roles and direct grants keep working. Additive: nothing is revoked;
 * the legacy names stay in the catalog and are reported as stale.
 */
return new class extends Migration
{
    private const NEW_ROLES = [
        GrievanceRoles::COMMITTEE_WRITER_ROLE,
        GrievanceRoles::APPROVER_ROLE,
        GrievanceRoles::ADMINISTRATOR_ROLE,
        GrievanceRoles::REGISTRY_ROLE,
        GrievanceRoles::OVERSIGHT_ROLE,
    ];

    public function up(): void
    {
        $rbac = app(RbacSynchronizer::class);
        $rbac->syncCatalog();

        // Only an installed system (roles already seeded) gets the new roles
        // here; a fresh database gets every role from RoleSeeder.
        if (Role::query()->where('guard_name', PermissionCatalog::GUARD)->exists()) {
            $definitions = DefaultRoleMatrix::roles();
            foreach (self::NEW_ROLES as $name) {
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

        $this->grantLegacyEquivalents();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Permissions are revoked with their rows; roles created here are
        // removed only if nobody holds them.
        $names = array_column(require database_path('seeders/data/grievance-permissions.php'), 'name');
        Permission::query()->whereIn('name', $names)->where('guard_name', PermissionCatalog::GUARD)->delete();

        foreach (self::NEW_ROLES as $name) {
            $role = Role::query()->where('name', $name)->where('guard_name', PermissionCatalog::GUARD)->first();
            if ($role !== null && ! DB::table('model_has_roles')->where('role_id', $role->getKey())->exists()) {
                $role->delete();
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function grantLegacyEquivalents(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (GrievanceRoles::legacyEquivalents() as $legacy => $equivalents) {
            $legacyPermission = Permission::query()->where('name', $legacy)->where('guard_name', PermissionCatalog::GUARD)->first();
            if ($legacyPermission === null) {
                continue;
            }
            $grant = Permission::query()->whereIn('name', $equivalents)->where('guard_name', PermissionCatalog::GUARD)->pluck('id')->all();
            if ($grant === []) {
                continue;
            }

            $roleIds = DB::table('role_has_permissions')->where('permission_id', $legacyPermission->getKey())->pluck('role_id');
            foreach ($roleIds as $roleId) {
                foreach ($grant as $permissionId) {
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
                }
            }

            $direct = DB::table('model_has_permissions')->where('permission_id', $legacyPermission->getKey())->get(['model_type', 'model_id']);
            foreach ($direct as $holder) {
                foreach ($grant as $permissionId) {
                    DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'model_type' => $holder->model_type, 'model_id' => $holder->model_id]);
                }
            }
        }
    }
};
