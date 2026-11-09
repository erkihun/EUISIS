<?php

declare(strict_types=1);

// migration-safety: reviewed — adds two permissions and grants them where the old shared grant was held; nothing is removed.

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Owner rule 2026-11-08: service feedback, Tip-offs and complaints are
 * separate modules. Tip-offs were read and reviewed under the service
 * feedback grants; they now have their own (service_feedback.tip_offs.view
 * and .review). Every role and user holding the old grant receives the new
 * one, so nobody loses access today; an administrator can then separate them.
 */
return new class extends Migration
{
    private const PAIRS = [
        'service_feedback.view' => 'service_feedback.tip_offs.view',
        'service_feedback.review' => 'service_feedback.tip_offs.review',
    ];

    public function up(): void
    {
        if (DB::pretending()) {
            return;
        }
        $catalog = collect(require database_path('seeders/data/permissions.php'))->keyBy('name');
        foreach (self::PAIRS as $old => $new) {
            $permission = Permission::query()->firstOrCreate(['name' => $new, 'guard_name' => 'web'], $catalog[$new] ?? []);
            $oldId = Permission::query()->where('name', $old)->where('guard_name', 'web')->value('id');
            if ($oldId === null) {
                continue;
            }
            foreach (DB::table('role_has_permissions')->where('permission_id', $oldId)->pluck('role_id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permission->id, 'role_id' => $roleId]);
            }
            foreach (DB::table('model_has_permissions')->where('permission_id', $oldId)->get(['model_type', 'model_id']) as $row) {
                DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $permission->id, 'model_type' => $row->model_type, 'model_id' => $row->model_id]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Grants are kept: removing them could lock handlers out of Tip-offs.
    }
};
