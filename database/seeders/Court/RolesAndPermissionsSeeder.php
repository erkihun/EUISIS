<?php

namespace Database\Seeders\Court;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
        ]);

        $this->assignAdminPermissions();
    }

    private function assignAdminPermissions(): void
    {
        $schema = DB::getSchemaBuilder();
        if (!$schema->hasTable('court_roles') || !$schema->hasTable('court_permissions') || !$schema->hasTable('court_permission_role')) {
            $this->command->warn("Skipping RolesAndPermissionsSeeder: required tables missing.");
            return;
        }

        $adminRoleId = DB::table('court_roles')->where('name', 'admin')->value('id');
        if (!$adminRoleId) {
            $this->command->warn("Skipping RolesAndPermissionsSeeder: 'admin' role not found.");
            return;
        }

        $permissionIds = DB::table('court_permissions')->pluck('id');

        foreach ($permissionIds as $permissionId) {
            DB::table('court_permission_role')->updateOrInsert(
                ['role_id' => $adminRoleId, 'permission_id' => $permissionId],
                []
            );
        }
    }
}
