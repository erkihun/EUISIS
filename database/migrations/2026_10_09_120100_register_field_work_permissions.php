<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Support\DailyActivity\DailyActivityRoles;
use App\Support\FieldWork\FieldWorkRoles;
use App\Support\Performance\PerformanceRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Registers Field Work permissions, grants them to the existing default roles
 * (the same sets DefaultRoleMatrix uses), and seeds a starter type catalog.
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

        if (! DB::table('field_work_types')->exists()) {
            $now = now();
            $starter = [
                ['INSPECTION', 'Inspection', 'ኢንስፔክሽን'],
                ['SUPERVISION', 'Supervision', 'ሱፐርቪዥን'],
                ['TECH_SUPPORT', 'Technical Support', 'የቴክኒክ ድጋፍ'],
                ['MONITORING', 'Monitoring', 'ክትትል'],
                ['SITE_VISIT', 'Site Visit', 'የቦታ ጉብኝት'],
                ['FIELD_VERIFICATION', 'Field Verification', 'የመስክ ማረጋገጫ'],
                ['SERVICE_DELIVERY', 'Service Delivery', 'የአገልግሎት አሰጣጥ'],
            ];
            DB::table('field_work_types')->insert(array_map(static fn (array $row, int $index): array => [
                'id' => (string) Str::uuid7(),
                'code' => $row[0],
                'name_en' => $row[1],
                'name_am' => $row[2],
                'is_active' => true,
                'sort_order' => ($index + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ], $starter, array_keys($starter)));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Preserve permission assignments when rolling back schema for recovery.
    }
};
