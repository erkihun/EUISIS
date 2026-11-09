<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Align the organizational change request permissions with the hyphenated
 * resource convention used by the rest of the domain.
 *
 * The first cut shipped them with underscored prefixes
 * (`organization_change_requests.*`, `organization_units.request_*`), which
 * left two spellings of the same resource in the catalog: `organization_units`
 * beside the long-standing `organization-units`. This renames them in place.
 *
 * Renaming the `name` column preserves every role and user assignment, because
 * `role_has_permissions` and `model_has_permissions` reference `permission_id`
 * rather than the name. Nobody loses access.
 *
 * `positions.request_*` is untouched: "positions" is a single word and already
 * matches the existing `positions.*` permissions.
 */
return new class extends Migration
{
    /** Old prefix => new prefix. */
    private const PREFIX_RENAMES = [
        'organization_change_requests.' => 'organizational-change-requests.',
        'organization_units.request_' => 'organization-units.request_',
    ];

    /** Old group => new group. */
    private const GROUP_RENAMES = [
        'organization_change_requests' => 'organizational-change-requests',
        'organization_unit_requests' => 'organization-unit-requests',
        'position_requests' => 'position-requests',
    ];

    public function up(): void
    {
        $this->renamePrefixes(self::PREFIX_RENAMES);
        $this->renameGroups(self::GROUP_RENAMES);
    }

    public function down(): void
    {
        $this->renamePrefixes(array_flip(self::PREFIX_RENAMES));
        $this->renameGroups(array_flip(self::GROUP_RENAMES));
    }

    /** @param array<string, string> $renames */
    private function renamePrefixes(array $renames): void
    {
        foreach ($renames as $old => $new) {
            $rows = DB::table('permissions')
                ->where('name', 'like', str_replace('_', '\_', $old).'%')
                ->get(['id', 'name']);

            foreach ($rows as $row) {
                $renamed = $new.substr($row->name, strlen($old));

                // A row under the target name already exists (a re-run, or a
                // partial earlier attempt): drop the stale duplicate rather
                // than collide with the unique index.
                $existing = DB::table('permissions')
                    ->where('name', $renamed)
                    ->where('guard_name', 'web')
                    ->value('id');

                if ($existing !== null && $existing !== $row->id) {
                    DB::table('permissions')->where('id', $row->id)->delete();

                    continue;
                }

                DB::table('permissions')->where('id', $row->id)->update(['name' => $renamed]);
            }
        }
    }

    /** @param array<string, string> $renames */
    private function renameGroups(array $renames): void
    {
        if (! Schema::hasColumn('permissions', 'group')) {
            return;
        }

        foreach ($renames as $old => $new) {
            DB::table('permissions')->where('group', $old)->update(['group' => $new]);
        }
    }
};
