<?php

declare(strict_types=1);

use App\Services\Intelligence\IntelligencePermissions;
use App\Support\Intelligence\IntelligenceRoles;
use App\Support\Rbac\RbacSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Addis One Intelligence grants (docs/intelligence-architecture.md).
 * Additive only: registers the catalog entries, gives Super Admin and System
 * Admin everything, and the city-wide business roles everything except the
 * technical grants (IntelligenceRoles::CITY_ADMIN_WITHHELD). No scoped role
 * is granted anything — NEEDS_POLICY_DECISION_ROLE_GRANTS.
 */
return new class extends Migration
{
    private const FULL_ROLES = ['Super Admin', 'System Admin'];

    private const BUSINESS_ROLES = ['City Admin', 'Public Service Bureau Admin'];

    public function up(): void
    {
        if (DB::pretending() || ! Schema::hasTable('permissions')) {
            return;
        }

        app(RbacSynchronizer::class)->syncCatalog();

        $all = [...IntelligencePermissions::all(), IntelligencePermissions::SETTINGS];
        foreach ($all as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::FULL_ROLES as $roleName) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($all);
        }
        $business = array_values(array_diff($all, IntelligenceRoles::CITY_ADMIN_WITHHELD));
        foreach (self::BUSINESS_ROLES as $roleName) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($business);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (DB::pretending() || ! Schema::hasTable('permissions')) {
            return;
        }

        Permission::query()->whereIn('name', [...IntelligencePermissions::all(), IntelligencePermissions::SETTINGS])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
