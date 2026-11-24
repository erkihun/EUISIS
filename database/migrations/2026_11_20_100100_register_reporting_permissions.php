<?php

declare(strict_types=1);

use App\Models\Role;
use App\Support\Rbac\DefaultRoleMatrix;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\RbacSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Spatie\Permission\PermissionRegistrar;

/*
 * Registers the Institutional Reporting permissions, creates the module's
 * six default roles on installed systems (fresh installs get them from
 * RoleSeeder) and grants the matrix sets additively, as the Letters
 * registration does. Roles and permissions are the Core's tables, which is
 * why this migration lives here and not in the module.
 *
 * Additive only: nothing is removed from any existing role.
 */
return new class extends Migration
{
    private const NEW_ROLES = [
        ReportingRoles::PREPARER_ROLE,
        ReportingRoles::OFFICER_ROLE,
        ReportingRoles::REVIEWER_ROLE,
        ReportingRoles::APPROVER_ROLE,
        ReportingRoles::OVERSIGHT_ROLE,
        ReportingRoles::ADMINISTRATOR_ROLE,
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
                if (Schema::hasColumn('roles', 'name_am')) {
                    $attributes += ['name_am' => DefaultRoleMatrix::NAMES_AM[$name] ?? null];
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
        // Permissions and roles are kept: removing them would silently strip access people were given.
    }
};
