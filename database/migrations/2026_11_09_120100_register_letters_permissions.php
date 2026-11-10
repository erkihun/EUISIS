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
use Modules\Letters\Contracts\Data\LettersRoles;
use Spatie\Permission\PermissionRegistrar;

/*
 * Registers the Letters permissions (ADR 0037), creates the module's five
 * default roles on installed systems (fresh installs get them from RoleSeeder)
 * and grants the matrix sets additively, as the service-monitoring
 * registration does.
 */
return new class extends Migration
{
    private const NEW_ROLES = [
        LettersRoles::REGISTRAR_ROLE,
        LettersRoles::OFFICER_ROLE,
        LettersRoles::SIGNATORY_ROLE,
        LettersRoles::ADMINISTRATOR_ROLE,
        LettersRoles::TEMPLATE_STEWARD_ROLE,
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
        $names = array_column(require database_path('seeders/data/letters-permissions.php'), 'name');
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
