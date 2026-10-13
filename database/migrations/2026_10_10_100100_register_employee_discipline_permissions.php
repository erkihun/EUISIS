<?php

declare(strict_types=1);

use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Models\CodeRule;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Discipline\DisciplineRoles;
use App\Support\Rbac\DefaultRoleMatrix;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\RbacSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/*
 * Registers the Employee Discipline permissions, creates the three discipline
 * roles on installed systems (fresh installs get them from RoleSeeder), grants
 * the matrix sets additively and seeds the disciplinary case number rule.
 */
return new class extends Migration
{
    private const NEW_ROLES = [
        DisciplineRoles::CASE_OFFICER_ROLE,
        DisciplineRoles::COMMITTEE_MEMBER_ROLE,
        DisciplineRoles::DECISION_AUTHORITY_ROLE,
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

        CodeRule::query()->firstOrCreate(
            ['active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::DisciplinaryCase)],
            [
                'entity_type' => CodeRuleEntityType::DisciplinaryCase,
                'name_en' => 'Disciplinary Case Number',
                'name_am' => 'የዲሲፕሊን ጉዳይ ቁጥር',
                'prefix' => 'DSC',
                'format' => '{PREFIX}-{YEAR}-{SEQUENCE}',
                'separator' => '-',
                'sequence_length' => 6,
                'next_number' => 1,
                'reset_frequency' => CodeRuleResetFrequency::Yearly,
                'year_format' => 'Y',
                'is_active' => true,
                'allow_manual_override' => false,
                'require_approval_for_override' => true,
            ],
        );
    }

    public function down(): void
    {
        $names = array_column(require database_path('seeders/data/discipline-permissions.php'), 'name');
        Permission::query()->whereIn('name', $names)->where('guard_name', PermissionCatalog::GUARD)->delete();

        foreach (self::NEW_ROLES as $name) {
            $role = Role::query()->where('name', $name)->where('guard_name', PermissionCatalog::GUARD)->first();
            if ($role !== null && ! DB::table('model_has_roles')->where('role_id', $role->getKey())->exists()) {
                $role->delete();
            }
        }
        // The code rule and its sequence history are kept.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
