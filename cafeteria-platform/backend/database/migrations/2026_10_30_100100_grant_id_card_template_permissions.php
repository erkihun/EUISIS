<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The EUISIS template designer's permissions (id_card_templates.*) for the
 * system roles: everyone who sees cards sees the templates; administrators
 * design them. Only these are added; anything an administrator took away
 * from a role stays taken away.
 */
return new class extends Migration
{
    private const GRANTS = [
        'core_viewer' => ['id_card_templates.view'],
        'core_officer' => ['id_card_templates.view'],
        'core_admin' => ['id_card_templates.view', 'id_card_templates.create', 'id_card_templates.update', 'id_card_templates.delete', 'id_card_templates.set_default'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $role => $permissions) {
            $id = DB::table('core_roles')->where('key', $role)->value('id');
            if ($id !== null) {
                DB::table('core_role_permissions')->insertOrIgnore(array_map(fn (string $p): array => ['core_role_id' => $id, 'permission' => $p], $permissions));
            }
        }
        cache()->forget('core.roles');
    }

    public function down(): void
    {
        foreach (self::GRANTS as $role => $permissions) {
            $id = DB::table('core_roles')->where('key', $role)->value('id');
            DB::table('core_role_permissions')->where('core_role_id', $id)->whereIn('permission', $permissions)->delete();
        }
        cache()->forget('core.roles');
    }
};
