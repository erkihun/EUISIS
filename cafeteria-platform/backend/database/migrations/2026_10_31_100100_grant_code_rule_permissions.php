<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The EUISIS Code Rules permissions (code-rules.*) for the system roles:
 * officers see the rules and their previews, administrators write them.
 * Only these are added; anything an administrator took away from a role
 * stays taken away.
 */
return new class extends Migration
{
    private const GRANTS = [
        'core_officer' => ['code-rules.viewAny', 'code-rules.view', 'code-rules.preview', 'code-rules.generate'],
        'core_admin' => [
            'code-rules.viewAny', 'code-rules.view', 'code-rules.preview', 'code-rules.generate',
            'code-rules.create', 'code-rules.update', 'code-rules.archive', 'code-rules.restore', 'code-rules.viewDeleted',
            'code-rules.viewSequences', 'code-rules.resetSequence',
        ],
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
