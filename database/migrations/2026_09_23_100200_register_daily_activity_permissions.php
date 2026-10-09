<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Permission;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Support\DailyActivity\DailyActivityRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Registers Daily Activity permissions and the two roles that carry them.
 *
 * Self-registered employees receive no role today, so without an Employee
 * role nobody could hold daily_activities.view_own. Existing accounts linked
 * to an employee record (the same email join User::employee() uses) are
 * backfilled; new ones receive the role at registration.
 *
 * tracking_start_date is pinned to the day this runs, so years of history
 * before the module existed are never reported as missing activity.
 */
return new class extends Migration
{
    public function up(): void
    {
        $catalog = require database_path('seeders/data/daily-activity-permissions.php');

        foreach ($catalog as $entry) {
            Permission::query()->updateOrCreate(
                ['name' => $entry['name'], 'guard_name' => 'web'],
                array_diff_key($entry, ['name' => true]),
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = array_column($catalog, 'name');

        $grants = [
            'Super Admin' => $all,
            'System Admin' => $all,
            'City Admin' => $all,
            'Public Service Bureau Admin' => $all,
            'Organizational Admin' => DailyActivityRoles::ORGANIZATIONAL_ADMIN_PERMISSIONS,
            'HR Officer' => DailyActivityRoles::HR_OVERSIGHT_PERMISSIONS,
        ];

        foreach ($grants as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            $role?->givePermissionTo($permissions);
        }

        foreach ([
            DailyActivityRoles::EMPLOYEE_ROLE => DailyActivityRoles::EMPLOYEE_PERMISSIONS,
            DailyActivityRoles::REVIEWER_ROLE => DailyActivityRoles::REVIEWER_PERMISSIONS,
        ] as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->forceFill(['scope_type' => 'scoped'])->save();
            $role->givePermissionTo($permissions);
        }

        $employeeRole = Role::findByName(DailyActivityRoles::EMPLOYEE_ROLE, 'web');

        User::query()
            ->whereIn('email', Employee::query()->whereNotNull('email')->select('email'))
            ->where(fn ($query) => $query->whereNull('user_type')->orWhere('user_type', '!=', 'provider'))
            ->chunkById(500, function ($users) use ($employeeRole): void {
                foreach ($users as $user) {
                    if (! $user->hasRole($employeeRole)) {
                        $user->assignRole($employeeRole);
                    }
                }
            });

        $definition = SystemSettingsRegistry::definition(SystemSettingsRegistry::GROUP_DAILY_ACTIVITY, 'tracking_start_date');

        SystemSetting::query()->firstOrCreate(
            ['group' => SystemSettingsRegistry::GROUP_DAILY_ACTIVITY, 'key' => 'tracking_start_date'],
            [
                'value' => now('Africa/Addis_Ababa')->toDateString(),
                'type' => $definition['type'],
                'label_en' => $definition['label_en'],
                'label_am' => $definition['label_am'],
                'description_en' => $definition['description_en'],
                'description_am' => $definition['description_am'],
                'is_public' => false,
                'is_encrypted' => false,
                'is_system' => true,
                'is_required' => false,
                'sort_order' => $definition['sort_order'],
                'validation_rules' => $definition['validation_rules'],
            ],
        );

        Cache::forget('system_settings_all');
        Cache::forget('system_settings_public');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Preserve permission assignments when rolling back schema for recovery.
    }
};
