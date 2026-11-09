<?php

declare(strict_types=1);

use App\Support\Rbac\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/*
 * The planned Court Cases module's only permission goes with its placeholder
 * page: the court module (ADR 0020) and its court.* permissions replace it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('permissions')->where('name', 'court_cases.view')->where('guard_name', PermissionCatalog::GUARD)->value('id');
        if ($id !== null) {
            DB::table('role_has_permissions')->where('permission_id', $id)->delete();
            DB::table('model_has_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // The placeholder module is gone; nothing to restore it to.
    }
};
