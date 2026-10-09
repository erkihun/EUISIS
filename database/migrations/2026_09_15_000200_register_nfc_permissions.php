<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $catalog = require database_path('seeders/data/permissions.php');
        foreach ($catalog as $entry) {
            if (! str_starts_with($entry['name'], 'nfc_')) {
                continue;
            }
            $permission = Permission::firstOrCreate(['name' => $entry['name'], 'guard_name' => 'web'], $entry);
            foreach (Role::where('guard_name', 'web')->whereIn('name', ['Super Admin', 'System Admin'])->get() as $role) {
                $role->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Preserve permission assignments when rolling back schema for recovery.
    }
};
