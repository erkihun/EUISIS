<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grants the P3 permissions (ADR 0010) to the system roles that existed before
 * them. Only these new permissions are added; anything an administrator took
 * out of a role earlier stays out. Custom roles get nothing automatically.
 */
return new class extends Migration
{
    private const GRANTS = [
        'core_viewer' => ['card_templates.view', 'card_print_batches.view', 'digital_credentials.view'],
        'core_officer' => ['card_templates.view', 'card_print_batches.view', 'digital_credentials.view',
            'positions.approve', 'cards.quality_check', 'card_print_batches.manage', 'digital_credentials.issue', 'digital_credentials.revoke', 'break_glass.request'],
        'core_admin' => ['card_templates.view', 'card_print_batches.view', 'digital_credentials.view',
            'positions.approve', 'cards.quality_check', 'card_print_batches.manage', 'digital_credentials.issue', 'digital_credentials.revoke', 'break_glass.request',
            'positions.master.manage', 'cards.reprint', 'card_templates.manage', 'digital_credentials.rotate',
            'audit.integrity.view', 'audit.integrity.verify', 'break_glass.approve', 'break_glass.revoke'],
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
