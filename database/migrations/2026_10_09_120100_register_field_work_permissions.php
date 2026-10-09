<?php

declare(strict_types=1);

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = ['field_work.view_own', 'field_work.create', 'field_work.update_own', 'field_work.submit', 'field_work.complete', 'field_work.view_team', 'field_work.approve', 'field_work.return', 'field_work.reject', 'field_work.view_scoped', 'field_work.view_reports', 'field_work.manage_types', 'field_work.view_team_availability', 'field_work.location.capture_own', 'field_work.location.view_own', 'field_work.location.view_team', 'field_work.location.view_org', 'field_work.location.view_precise', 'field_work.location.export'];

    public function up(): void
    {
        $now = now();
        DB::table('permissions')->upsert(
            array_map(static fn (string $name): array => ['name' => $name, 'guard_name' => 'web', 'group' => 'Field Work', 'description' => 'Field Work management', 'created_at' => $now, 'updated_at' => $now], self::PERMISSIONS),
            ['name', 'guard_name'],
            ['group', 'description', 'updated_at'],
        );

        if (DB::connection()->pretending()) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['Super Admin', 'System Admin', 'City Admin', 'Public Service Bureau Admin'] as $role) {
            Role::query()->where('name', $role)->where('guard_name', 'web')->first()?->givePermissionTo(self::PERMISSIONS);
        }
        Role::query()->where('name', 'Employee')->where('guard_name', 'web')->first()?->givePermissionTo(['field_work.view_own', 'field_work.create', 'field_work.update_own', 'field_work.submit', 'field_work.complete', 'field_work.location.capture_own', 'field_work.location.view_own']);
        Role::query()->where('name', 'Daily Activity Reviewer')->where('guard_name', 'web')->first()?->givePermissionTo(['field_work.view_team', 'field_work.approve', 'field_work.return', 'field_work.reject', 'field_work.complete', 'field_work.location.view_team']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
