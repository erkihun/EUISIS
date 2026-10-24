<?php

declare(strict_types=1);

use App\Support\Rbac\RbacSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Core workspace permission. Additive only: registers the catalog entry
 * and grants it to the citywide administration roles that DefaultRoleMatrix
 * gives it to, so a live database matches a fresh seed without reseeding.
 * Super Admin passes every gate; the grant keeps its role row complete.
 */
return new class extends Migration
{
    private const PERMISSION = 'core.access';

    private const ROLES = ['Super Admin', 'System Admin', 'City Admin', 'Public Service Bureau Admin'];

    public function up(): void
    {
        if (DB::pretending() || ! Schema::hasTable('permissions')) {
            return;
        }

        app(RbacSynchronizer::class)->syncCatalog();
        Permission::findOrCreate(self::PERMISSION, 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ROLES as $roleName) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo(self::PERMISSION);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (DB::pretending() || ! Schema::hasTable('permissions')) {
            return;
        }

        Permission::query()->where('name', self::PERMISSION)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
