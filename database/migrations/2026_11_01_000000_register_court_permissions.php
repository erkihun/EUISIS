<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\RbacSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/*
 * Registers the court module's permissions (database/seeders/data/court-permissions.php,
 * ADR 0020). Roles get them on the EUISIS Roles page; Super Admin holds them all.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(RbacSynchronizer::class)->syncCatalog();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $names = array_column(require database_path('seeders/data/court-permissions.php'), 'name');
        Permission::query()->whereIn('name', $names)->where('guard_name', PermissionCatalog::GUARD)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
