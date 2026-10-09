<?php

declare(strict_types=1);

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = ['field_work.complete', 'field_work.return', 'field_work.reject'];

    public function up(): void
    {
        $now = now();
        DB::table('permissions')->upsert(
            array_map(static fn (string $name): array => ['name' => $name, 'guard_name' => 'web', 'group' => 'Field Work', 'description' => 'Field Work workflow action', 'created_at' => $now, 'updated_at' => $now], self::PERMISSIONS),
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
        Role::query()->where('name', 'Employee')->where('guard_name', 'web')->first()?->givePermissionTo('field_work.complete');
        Role::query()->where('name', 'Daily Activity Reviewer')->where('guard_name', 'web')->first()?->givePermissionTo(self::PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
