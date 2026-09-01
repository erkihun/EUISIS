<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')
            ->where('name', 'positions.move')
            ->where('guard_name', 'web')
            ->value('id');

        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'name' => 'positions.move',
                'guard_name' => 'web',
                'label_en' => 'Move Positions',
                'label_am' => 'የሥራ መደቦችን አንቀሳቅስ',
                'description_en' => 'Allows moving a vacant position between units in the same organization.',
                'description_am' => 'ክፍት የሥራ መደብን በአንድ ተቋም ውስጥ ባሉ ዩኒቶች መካከል ለማንቀሳቀስ ያስችላል።',
                'group' => 'positions',
                'sort_order' => 45,
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $roleIds = DB::table('roles')
            ->where('guard_name', 'web')
            ->whereIn('name', ['Super Admin', 'System Admin', 'Organizational Admin'])
            ->pluck('id');

        DB::table('role_has_permissions')->insertOrIgnore(
            $roleIds
                ->map(fn ($roleId): array => [
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ])
                ->all(),
        );
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('name', 'positions.move')
            ->where('guard_name', 'web')
            ->value('id');

        if ($permissionId === null) {
            return;
        }

        DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
