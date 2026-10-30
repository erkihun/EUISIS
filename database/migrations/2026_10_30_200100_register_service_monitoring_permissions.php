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
use Modules\ServiceMonitoring\Contracts\Data\ServiceMonitoringRoles;
use Spatie\Permission\PermissionRegistrar;

/*
 * Registers the Service Delivery Monitoring & Support permissions, creates the
 * module's six default roles on installed systems (fresh installs get them
 * from RoleSeeder) and grants the matrix sets additively, as the discipline
 * registration does.
 */
return new class extends Migration
{
    private const NEW_ROLES = [
        ServiceMonitoringRoles::REPORTER_ROLE,
        ServiceMonitoringRoles::HEAD_ROLE,
        ServiceMonitoringRoles::REVIEWER_ROLE,
        ServiceMonitoringRoles::COORDINATOR_ROLE,
        ServiceMonitoringRoles::VERIFIER_ROLE,
        ServiceMonitoringRoles::SUPPORT_OFFICER_ROLE,
    ];

    public function up(): void
    {
        $rbac = app(RbacSynchronizer::class);
        $rbac->syncCatalog();

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
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $names = array_column(require database_path('seeders/data/service-monitoring-permissions.php'), 'name');
        Permission::query()->whereIn('name', $names)->where('guard_name', PermissionCatalog::GUARD)->delete();

        foreach (self::NEW_ROLES as $name) {
            $role = Role::query()->where('name', $name)->where('guard_name', PermissionCatalog::GUARD)->first();
            if ($role !== null && ! DB::table('model_has_roles')->where('role_id', $role->getKey())->exists()) {
                $role->delete();
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
