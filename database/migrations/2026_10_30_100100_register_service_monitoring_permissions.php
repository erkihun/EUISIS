<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Support\ServiceMonitoring\ServiceMonitoringRoles;
use Database\Seeders\ServiceMonitoringStarterFormSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Registers the Service Delivery Monitoring permissions, grants them to the
 * existing default roles (the same sets DefaultRoleMatrix uses) and seeds the
 * starter form as a draft.
 *
 * Additive only: no grant is revoked and an existing form is never changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $catalog = require database_path('seeders/data/service-monitoring-permissions.php');

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
            'Organizational Admin' => ServiceMonitoringRoles::ORGANIZATIONAL_ADMIN_PERMISSIONS,
        ];
        foreach ($grants as $roleName => $permissions) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permissions);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        (new ServiceMonitoringStarterFormSeeder)->run();
    }

    public function down(): void
    {
        // Preserve permission assignments when rolling back schema for recovery.
    }
};
