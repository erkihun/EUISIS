<?php

declare(strict_types=1);

use App\Support\Rbac\RbacSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/*
 * ADR 0026: the default roles that already exist gain the permissions the completed matrix
 * adds to them (position establishments for the structure roles, verification and export for
 * the ID card roles, custom permissions for the Security Settings Manager, the employee's own
 * transfer and vacancy applications, and so on). Nothing is removed and no role is created:
 * the 38 new roles come from `php artisan db:seed --class=RoleSeeder --force` (safe to repeat)
 * or the Roles page, so that no migration ever creates a role.
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
        // Additive grants on existing roles; the matrix (RolePermissionSeeder in sync mode) is
        // the authority for what each role holds, so there is nothing to undo here.
    }
};
