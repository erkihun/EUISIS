<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Support\DailyActivity\DailyActivityRoles;
use App\Support\FieldWork\FieldWorkRoles;
use App\Support\Performance\PerformanceRoles;
use App\Support\Rbac\PermissionCatalog;
use Database\Seeders\FieldWorkTypeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Registers Field Work permissions, grants them to the existing default roles
 * (the same sets DefaultRoleMatrix uses), registers the Field Work GPS policy
 * settings permission, and seeds the starter type catalog.
 *
 * Additive only: no grant is revoked, no user assignment changes, and the
 * starter types are inserted only when the catalog is empty, so an
 * administrator's catalog is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        $catalog = require database_path('seeders/data/field-work-permissions.php');

        foreach ($catalog as $entry) {
            Permission::query()->updateOrCreate(
                ['name' => $entry['name'], 'guard_name' => 'web'],
                array_diff_key($entry, ['name' => true]),
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = array_column($catalog, 'name');
        $cityWide = array_values(array_diff($all, FieldWorkRoles::CITY_ADMIN_WITHHELD));

        $grants = [
            'Super Admin' => $all,
            'System Admin' => $all,
            'City Admin' => $cityWide,
            'Public Service Bureau Admin' => $cityWide,
            'Organizational Admin' => FieldWorkRoles::ORGANIZATIONAL_ADMIN_PERMISSIONS,
            'HR Officer' => FieldWorkRoles::HR_OVERSIGHT_PERMISSIONS,
            DailyActivityRoles::EMPLOYEE_ROLE => FieldWorkRoles::EMPLOYEE_PERMISSIONS,
            DailyActivityRoles::REVIEWER_ROLE => FieldWorkRoles::SUPERVISOR_PERMISSIONS,
            PerformanceRoles::MANAGER_ROLE => FieldWorkRoles::SUPERVISOR_PERMISSIONS,
        ];

        foreach ($grants as $roleName => $permissions) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permissions);
        }

        // GPS policy administration lives in System Settings (system-settings group).
        $gps = PermissionCatalog::all()['system-settings.manageFieldWorkGps'];
        Permission::query()->updateOrCreate(['name' => $gps['name'], 'guard_name' => 'web'], array_diff_key($gps, ['name' => true]));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['Super Admin', 'System Admin'] as $roleName) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($gps['name']);
        }

        // Same editable starter catalog as FieldWorkTypeSeeder; only into an empty table.
        if (! DB::table('field_work_types')->exists()) {
            (new FieldWorkTypeSeeder)->run();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Preserve permission assignments when rolling back schema for recovery.
    }
};
