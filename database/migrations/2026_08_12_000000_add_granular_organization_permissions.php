<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $legacyPermissionId = DB::table('permissions')
            ->where('name', 'organizations.manage')
            ->where('guard_name', 'web')
            ->value('id');

        foreach ($this->permissions() as $name => $labels) {
            $permissionId = DB::table('permissions')
                ->where('name', $name)
                ->where('guard_name', 'web')
                ->value('id');

            if ($permissionId === null) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'name' => $name,
                    'guard_name' => 'web',
                    'label_en' => $labels['en'],
                    'label_am' => $labels['am'],
                    'group' => 'organizations',
                    'sort_order' => match ($name) {
                        'organizations.create' => 20,
                        'organizations.update' => 30,
                        default => 40,
                    },
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($legacyPermissionId === null) {
                continue;
            }

            DB::table('role_has_permissions')->insertOrIgnore(
                DB::table('role_has_permissions')
                    ->where('permission_id', $legacyPermissionId)
                    ->get(['role_id'])
                    ->map(fn ($row): array => ['permission_id' => $permissionId, 'role_id' => $row->role_id])
                    ->all(),
            );

            DB::table('model_has_permissions')->insertOrIgnore(
                DB::table('model_has_permissions')
                    ->where('permission_id', $legacyPermissionId)
                    ->get(['model_type', 'model_id'])
                    ->map(fn ($row): array => [
                        'permission_id' => $permissionId,
                        'model_type' => $row->model_type,
                        'model_id' => $row->model_id,
                    ])->all(),
            );
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', array_keys($this->permissions()))->delete();
    }

    /** @return array<string, array{en: string, am: string}> */
    private function permissions(): array
    {
        return [
            'organizations.create' => ['en' => 'Create Organizations', 'am' => 'ድርጅቶችን ይፍጠሩ'],
            'organizations.update' => ['en' => 'Update Organizations', 'am' => 'ድርጅቶችን ያዘምኑ'],
            'organizations.delete' => ['en' => 'Delete Organizations', 'am' => 'ድርጅቶችን ይሰርዙ'],
        ];
    }
};
