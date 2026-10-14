<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Services\SituationRoom\SituationPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Executive Situation Room categories (docs/situation-room-architecture.md).
 * Additive only. Registers the per-domain grants (the core situation_room.*
 * grants and the Action Register come from 2026_10_14_130000/130100), keeps
 * every existing holder of situation_room.view able to open Community Voice,
 * gives the city-wide admin roles the whole room, and adds one index the
 * public-voice aggregates use. No existing row is changed.
 */
return new class extends Migration
{
    private const ADMIN_ROLES = ['Super Admin', 'System Admin', 'City Admin', 'Public Service Bureau Admin'];

    public function up(): void
    {
        $catalog = require database_path('seeders/data/situation-room-permissions.php');
        foreach ($catalog as $entry) {
            Permission::query()->updateOrCreate(['name' => $entry['name'], 'guard_name' => 'web'], array_diff_key($entry, ['name' => true]));
        }
        // The core grants may not exist yet on a database that skipped their migration.
        foreach (SituationPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Community Voice now also needs public_voice.view: whoever could open it keeps it.
        Role::query()->where('guard_name', 'web')
            ->whereHas('permissions', fn ($q) => $q->where('name', SituationPermissions::VIEW))
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo(SituationPermissions::PUBLIC_VOICE));

        foreach (self::ADMIN_ROLES as $roleName) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()
                ?->givePermissionTo([...SituationPermissions::all(), 'system-settings.manageSituationRoom']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Scope-first public-voice aggregates: organization, then type, then the period column.
        if (! $this->hasIndex('employee_service_feedback', 'esf_org_type_submitted_idx')) {
            Schema::table('employee_service_feedback', function (Blueprint $table): void {
                $table->index(['organization_id', 'type', 'submitted_at'], 'esf_org_type_submitted_idx');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('employee_service_feedback', 'esf_org_type_submitted_idx')) {
            Schema::table('employee_service_feedback', fn (Blueprint $table) => $table->dropIndex('esf_org_type_submitted_idx'));
        }

        // Only what this migration registered; the core grants belong to their own migration.
        $names = array_column(require database_path('seeders/data/situation-room-permissions.php'), 'name');
        Permission::query()->whereIn('name', $names)->get()->each(fn (Permission $p) => $p->delete());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $index): bool => $index['name'] === $name);
    }
};
