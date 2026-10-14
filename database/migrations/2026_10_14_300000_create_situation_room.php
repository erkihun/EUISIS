<?php

declare(strict_types=1);

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Executive Situation Room (docs/situation-room-architecture.md). Additive
 * only: registers the situation_room.* permissions, creates the Executive
 * Action Register and adds one index the public-voice aggregates use. No
 * existing row is changed.
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
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // City-wide administrators get the room; everyone else is granted it deliberately.
        foreach (self::ADMIN_ROLES as $roleName) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo(array_column($catalog, 'name'));
        }

        if (! Schema::hasTable('situation_actions')) {
            Schema::create('situation_actions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('reference_number', 32)->unique();
                $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
                $table->string('category', 40);
                $table->string('panel_key', 80)->nullable();
                $table->string('title', 200);
                $table->text('description')->nullable();
                $table->string('priority', 16)->default('normal');
                $table->string('status', 16)->default('open');
                $table->string('responsible_office', 200)->nullable();
                $table->date('due_date')->nullable();
                $table->text('outcome_note')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['organization_id', 'status'], 'sa_org_status_idx');
                $table->index(['status', 'due_date'], 'sa_status_due_idx');
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE situation_actions ADD CONSTRAINT sa_status_chk CHECK (status IN ('open', 'in_progress', 'done', 'cancelled'))");
                DB::statement("ALTER TABLE situation_actions ADD CONSTRAINT sa_priority_chk CHECK (priority IN ('low', 'normal', 'high', 'urgent'))");
            }
        }

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

        // The register holds executive decisions: never dropped while it has rows.
        if (Schema::hasTable('situation_actions') && ! DB::table('situation_actions')->exists()) {
            Schema::drop('situation_actions');
        }

        Permission::query()->where('group', 'situation_room')->get()->each(fn (Permission $p) => $p->delete());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $index): bool => $index['name'] === $name);
    }
};
