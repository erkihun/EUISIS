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
 * Event integration administration grants. Additive only: registers the
 * catalog entries and gives the API Manager role view, manage and delivery
 * history. Replay and payload inspection go to no role
 * (NEEDS_POLICY_DECISION_INTEGRATION_REPLAY_ROLE); Super Admin passes every gate.
 */
return new class extends Migration
{
    private const GRANTS = ['API Manager' => ['integrations.view', 'integrations.manage', 'integrations.deliveries.view']];

    private const ALL = ['integrations.view', 'integrations.manage', 'integrations.deliveries.view', 'integrations.replay', 'integrations.payload.view'];

    public function up(): void
    {
        if (DB::pretending() || ! Schema::hasTable('permissions')) {
            return;
        }

        app(RbacSynchronizer::class)->syncCatalog();
        foreach (self::ALL as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::GRANTS as $roleName => $permissions) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permissions);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (DB::pretending() || ! Schema::hasTable('permissions')) {
            return;
        }

        Permission::query()->whereIn('name', self::ALL)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
