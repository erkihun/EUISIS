<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\RbacSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/*
 * Registers employees.delete (removing a registration made by mistake) and adds it to the
 * existing HR Officer and Organizational Admin roles as the matrix says. No role is created.
 */
return new class extends Migration
{
    public function up(): void
    {
        $synchronizer = app(RbacSynchronizer::class);
        $synchronizer->syncCatalog();
        $synchronizer->applyMatrix(RbacSynchronizer::MODE_ADDITIVE, onlyExisting: true);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'employees.delete')->where('guard_name', PermissionCatalog::GUARD)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
