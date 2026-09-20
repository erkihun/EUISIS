<?php

declare(strict_types=1);

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Registers the Public Site Management permissions in existing environments
 * and creates the Public Site Manager role.
 *
 * Granted to Super Admin and System Admin, and to the new role. Deliberately
 * NOT granted to Organizational Admin: the public website speaks for the whole
 * institution, not for one organization in its scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        $catalog = require database_path('seeders/data/permissions.php');

        $permissions = [];
        foreach ($catalog as $entry) {
            if (! str_starts_with($entry['name'], 'public_')) {
                continue;
            }

            $permissions[] = Permission::firstOrCreate(
                ['name' => $entry['name'], 'guard_name' => 'web'],
                $entry,
            );
        }

        foreach (Role::where('guard_name', 'web')->whereIn('name', ['Super Admin', 'System Admin'])->get() as $role) {
            $role->givePermissionTo($permissions);
        }

        $manager = Role::firstOrCreate(['name' => 'Public Site Manager', 'guard_name' => 'web']);
        if (Schema::hasColumn('roles', 'scope_type')) {
            $manager->forceFill(['scope_type' => 'global'])->save();
        }
        $manager->givePermissionTo($permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Preserve permission assignments when rolling back schema for recovery,
        // matching the other permission-registration migrations.
    }
};
