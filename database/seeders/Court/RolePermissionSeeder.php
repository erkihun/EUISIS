<?php

namespace Database\Seeders\Court;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $schema = DB::getSchemaBuilder();
        if (!$schema->hasTable('court_roles') || !$schema->hasTable('court_permissions') || !$schema->hasTable('court_permission_role')) {
            $this->command->warn("Skipping RolePermissionSeeder: missing tables.");
            return;
        }

        $adminRoleId = DB::table('court_roles')->where('name', 'admin')->value('id');
        if (!$adminRoleId) {
            $this->command->warn("Admin role not found, skipping RolePermissionSeeder.");
            return;
        }

        $permIds = DB::table('court_permissions')->pluck('id');
        foreach ($permIds as $pid) {
            DB::table('court_permission_role')->updateOrInsert(
                ['role_id' => $adminRoleId, 'permission_id' => $pid],
                []
            );
        }
    }
}
