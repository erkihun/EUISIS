<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Support\Rbac\RbacSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/*
 * Registers the work_experience.* permissions
 * (database/seeders/data/work-experience-permissions.php) and grants the sets
 * of Modules\WorkExperience\Contracts\Data\WorkExperienceRoles to the default
 * roles that already exist, additively.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rbac = app(RbacSynchronizer::class);
        $rbac->syncCatalog();
        $rbac->ensureRoles(createMissing: false);
        $rbac->applyMatrix(RbacSynchronizer::MODE_ADDITIVE, onlyExisting: true);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $names = array_column(require database_path('seeders/data/work-experience-permissions.php'), 'name');
        Permission::query()->whereIn('name', $names)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
