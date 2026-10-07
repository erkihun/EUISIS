<?php

declare(strict_types=1);

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Registers the work standard permissions on existing installations.
 *
 * Managing (sub-services, tasks, draft standards) and approving (making a
 * standard version the one that measures work) are separate rights.
 * Organizational Admins manage within their scope; approval is granted to
 * the city-level roles only, pending the organization's decision on who
 * approves BPR standards (docs/daily-work-register.md).
 */
return new class extends Migration
{
    private const PERMISSIONS = ['work_standards.manage', 'work_standards.approve'];

    public function up(): void
    {
        $catalog = collect(require database_path('seeders/data/daily-activity-permissions.php'))
            ->whereIn('name', self::PERMISSIONS);

        foreach ($catalog as $entry) {
            Permission::query()->updateOrCreate(
                ['name' => $entry['name'], 'guard_name' => 'web'],
                array_diff_key($entry, ['name' => true]),
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $grants = [
            'Super Admin' => self::PERMISSIONS,
            'System Admin' => self::PERMISSIONS,
            'City Admin' => self::PERMISSIONS,
            'Public Service Bureau Admin' => self::PERMISSIONS,
            'Organizational Admin' => ['work_standards.manage'],
        ];

        foreach ($grants as $roleName => $permissions) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
