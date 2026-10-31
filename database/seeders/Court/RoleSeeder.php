<?php

namespace Database\Seeders\Court;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $schema = DB::getSchemaBuilder();
        if (!$schema->hasTable('court_roles')) {
            $this->command->warn("Skipping RoleSeeder: 'roles' table not found.");
            return;
        }

        $roles = [
            ['name' => 'admin',  'description' => 'Administrator'],
            ['name' => 'staff',  'description' => 'Staff'],
            ['name' => 'viewer', 'description' => 'Viewer'],
        ];

        foreach ($roles as $r) {
            DB::table('court_roles')->updateOrInsert(['name' => $r['name']], $r);
        }
    }
}
