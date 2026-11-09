<?php

declare(strict_types=1);

use App\Support\Rbac\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/*
 * Reports, performance evaluations and the audit trail are EUISIS's own pages:
 * the court's copies are gone (ADR 0020), and so are their permissions. Court
 * record changes are on the EUISIS audit log (court_record.* events).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'court.audit.view',
        'court.performance-evaluations.create',
        'court.performance-evaluations.delete',
        'court.performance-evaluations.review',
        'court.performance-evaluations.update',
        'court.performance-evaluations.view',
        'court.reports.view',
    ];

    public function up(): void
    {
        $ids = DB::table('permissions')->whereIn('name', self::PERMISSIONS)->where('guard_name', PermissionCatalog::GUARD)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // The court's report, performance and audit pages are gone; nothing to restore them to.
    }
};
