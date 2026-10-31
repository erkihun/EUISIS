<?php

declare(strict_types=1);

use App\Support\Rbac\RbacSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Registers service_monitoring.forms.manage (the form template builder) and
 * grants it additively through the role matrix: the Service Monitoring
 * Coordinator, and the city-wide administrators that hold every module grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rbac = app(RbacSynchronizer::class);
        $rbac->syncCatalog();
        $rbac->applyMatrix(RbacSynchronizer::MODE_ADDITIVE, onlyExisting: true);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Preserve permission assignments when rolling back schema for recovery.
    }
};
