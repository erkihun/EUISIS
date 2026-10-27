<?php

declare(strict_types=1);

use App\Support\CafeteriaPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The cafeteria permissions (the EUISIS names) for the three system roles:
 * a viewer reads, an officer operates (scans, drafts, submits, exports), an
 * administrator does everything including approvals and settlements.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (CafeteriaPermissions::grants() as $role => $permissions) {
            $id = DB::table('core_roles')->where('key', $role)->value('id');
            if ($id !== null) {
                DB::table('core_role_permissions')->insertOrIgnore(array_map(fn (string $p): array => ['core_role_id' => $id, 'permission' => $p], $permissions));
            }
        }
        cache()->forget('core.roles');
    }

    public function down(): void
    {
        foreach (CafeteriaPermissions::grants() as $role => $permissions) {
            $id = DB::table('core_roles')->where('key', $role)->value('id');
            DB::table('core_role_permissions')->where('core_role_id', $id)->whereIn('permission', $permissions)->delete();
        }
        cache()->forget('core.roles');
    }
};
