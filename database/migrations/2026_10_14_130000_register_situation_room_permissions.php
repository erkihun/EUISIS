<?php

declare(strict_types=1);

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Executive Situation Room permissions (docs/situation-room-security.md).
 * Registered only — no role receives them here; which roles get them is a
 * business decision. firstOrCreate makes a retry harmless.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'situation_room.view',
        'situation_room.view_employee_targeted',
        'situation_room.view_detail',
        'situation_room.export',
        'situation_room.wall_mode',
        'situation_room.manage_actions',
    ];

    public function up(): void
    {
        if (DB::pretending() || ! Schema::hasTable('permissions')) {
            return;
        }

        $catalog = require database_path('seeders/data/permissions.php');

        foreach ($catalog as $entry) {
            if (in_array($entry['name'], self::PERMISSIONS, true)) {
                Permission::firstOrCreate(['name' => $entry['name'], 'guard_name' => 'web'], $entry);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', self::PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
