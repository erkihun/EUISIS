<?php

declare(strict_types=1);

use App\Support\Communication\CommunicationRoles;
use Modules\DailyWork\Contracts\Data\DailyActivityRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Registers the Communication permissions and gives them to the existing roles
 * as DefaultRoleMatrix describes, so an installed system has them without a
 * full re-seed. Additive and idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::pretending() || ! Schema::hasTable('permissions')) {
            return;
        }

        $catalog = require database_path('seeders/data/permissions.php');
        $names = [];
        foreach ($catalog as $entry) {
            if (in_array($entry['group'] ?? null, ['communication', 'communication_groups', 'communication_channels', 'communication_announcements', 'communication_moderation'], true)) {
                Permission::query()->updateOrCreate(['name' => $entry['name'], 'guard_name' => 'web'], array_diff_key($entry, ['name' => true]));
                $names[] = $entry['name'];
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $cityWide = array_values(array_diff($names, CommunicationRoles::CITY_ADMIN_WITHHELD));
        foreach ([
            'Super Admin' => $names,
            'System Admin' => $names,
            'City Admin' => $cityWide,
            'Public Service Bureau Admin' => $cityWide,
            'Organizational Admin' => CommunicationRoles::ORGANIZATIONAL_ADMIN_PERMISSIONS,
            'HR Officer' => CommunicationRoles::HR_PERMISSIONS,
            DailyActivityRoles::EMPLOYEE_ROLE => CommunicationRoles::EMPLOYEE_PERMISSIONS,
        ] as $roleName => $permissions) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permissions);
        }

        foreach ([
            CommunicationRoles::MODERATOR_ROLE => ['scoped', CommunicationRoles::MODERATOR_PERMISSIONS],
            CommunicationRoles::INVESTIGATOR_ROLE => ['global', CommunicationRoles::INVESTIGATOR_PERMISSIONS],
            CommunicationRoles::PRIVACY_OFFICER_ROLE => ['global', CommunicationRoles::PRIVACY_OFFICER_PERMISSIONS],
        ] as $roleName => [$scope, $permissions]) {
            $role = Role::findOrCreate($roleName, 'web');
            if (Schema::hasColumn('roles', 'scope_type')) {
                $role->forceFill(['scope_type' => $scope])->save();
            }
            $role->givePermissionTo($permissions);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Permissions stay: roles and users may have been given them since.
    }
};
