<?php

declare(strict_types=1);

use App\Models\SupportTicket;
use App\Support\Rbac\RbacSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Support help-desk grants (docs/assistant/support-helpdesk.md). Additive
 * only: registers the catalog entries and gives Super Admin and System Admin
 * both grants. Reporters need no grant to file and read their own tickets.
 * No other role is granted anything — NEEDS_POLICY_DECISION_SUPPORT_ROUTING.
 */
return new class extends Migration
{
    private const ROLES = ['Super Admin', 'System Admin'];

    public function up(): void
    {
        if (DB::pretending() || ! Schema::hasTable('permissions')) {
            return;
        }

        app(RbacSynchronizer::class)->syncCatalog();

        $all = [SupportTicket::PERMISSION_VIEW_ALL, SupportTicket::PERMISSION_MANAGE];
        foreach ($all as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ROLES as $roleName) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($all);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (DB::pretending() || ! Schema::hasTable('permissions')) {
            return;
        }

        Permission::query()->whereIn('name', [SupportTicket::PERMISSION_VIEW_ALL, SupportTicket::PERMISSION_MANAGE])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
