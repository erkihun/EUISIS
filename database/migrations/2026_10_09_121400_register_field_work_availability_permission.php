<?php

declare(strict_types=1);

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSION = 'field_work.view_team_availability';

    public function up(): void
    {
        $now = now();
        DB::table('permissions')->upsert(
            [['name' => self::PERMISSION, 'guard_name' => 'web', 'group' => 'Field Work', 'description' => 'View organization-scoped Field Work availability without precise location', 'created_at' => $now, 'updated_at' => $now]],
            ['name', 'guard_name'],
            ['group', 'description', 'updated_at'],
        );

        if (DB::connection()->pretending()) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['Super Admin', 'System Admin', 'City Admin', 'Public Service Bureau Admin'] as $role) {
            Role::query()->where('name', $role)->where('guard_name', 'web')->first()?->givePermissionTo(self::PERMISSION);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', self::PERMISSION)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
