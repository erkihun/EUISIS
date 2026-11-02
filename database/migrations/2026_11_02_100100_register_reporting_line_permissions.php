<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\RbacSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/*
 * Registers the reporting-line permissions (database/seeders/data/reporting-line-permissions.php,
 * ADR 0025) and adds them to the default roles the matrix names. No user gains a permission
 * through this migration: the Supervisor role it introduces is assigned by an administrator.
 */
return new class extends Migration
{
    public function up(): void
    {
        $synchronizer = app(RbacSynchronizer::class);
        $synchronizer->syncCatalog();
        // Existing default roles only gain what the matrix adds; no role is created here. The
        // Supervisor role comes from `db:seed --class=RoleSeeder` (safe to repeat) or the Roles page.
        $synchronizer->applyMatrix(RbacSynchronizer::MODE_ADDITIVE, onlyExisting: true);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $names = array_column(require database_path('seeders/data/reporting-line-permissions.php'), 'name');
        Permission::query()->whereIn('name', $names)->where('guard_name', PermissionCatalog::GUARD)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
