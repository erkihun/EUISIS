<?php

declare(strict_types=1);

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Workflow infrastructure for public submissions (brief §45–53): routing
 * rules, an append-only status history and an assignment history. Additive;
 * existing rows keep their status and gain history from their next change.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'service_feedback.assign',
        'service_feedback.reassign',
        'service_feedback.resolve',
        'service_feedback.close',
        'service_feedback.manage_routing',
    ];

    public function up(): void
    {
        Schema::create('public_feedback_routing_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Null = city-wide rule; otherwise the institution the rule serves.
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->string('target_type', 32)->nullable();
            $table->string('submission_type', 32)->nullable();
            $table->foreignUuid('category_id')->nullable()->constrained('public_feedback_categories')->restrictOnDelete();
            $table->string('handler_type', 32);
            $table->string('handler_id', 64);
            $table->unsignedInteger('priority')->default(100);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'is_active', 'priority'], 'pfrr_org_active_priority_idx');
        });

        Schema::create('public_feedback_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('submission_id')->constrained('employee_service_feedback')->cascadeOnDelete();
            $table->string('handler_type', 32);
            $table->string('handler_id', 64);
            $table->string('handler_name_snapshot');
            $table->string('handler_name_am_snapshot')->nullable();
            $table->foreignUuid('routing_rule_id')->nullable()->constrained('public_feedback_routing_rules')->nullOnDelete();
            // Null = assigned by a routing rule, not by a person.
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->dateTime('assigned_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['submission_id', 'assigned_at'], 'pfa_submission_assigned_idx');
            $table->index(['handler_type', 'handler_id', 'ended_at'], 'pfa_handler_open_idx');
        });

        // At most one open assignment per submission, on every driver.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // No partial indexes on MySQL: a stored column carries the submission of open rows only, and the unique index sits on it.
            DB::statement('ALTER TABLE public_feedback_assignments ADD COLUMN open_submission_id CHAR(36) GENERATED ALWAYS AS (CASE WHEN ended_at IS NULL THEN submission_id ELSE NULL END) STORED');
            DB::statement('CREATE UNIQUE INDEX pfa_one_open_per_submission ON public_feedback_assignments (open_submission_id)');
        } else {
            DB::statement('CREATE UNIQUE INDEX pfa_one_open_per_submission ON public_feedback_assignments (submission_id) WHERE ended_at IS NULL');
        }

        Schema::create('public_feedback_status_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('submission_id')->constrained('employee_service_feedback')->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            // Null = the system (submission, routing).
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->dateTime('transitioned_at');

            $table->index(['submission_id', 'transitioned_at'], 'pfsh_submission_time_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE public_feedback_routing_rules ADD CONSTRAINT pfrr_handler_type_chk CHECK (handler_type IN ('user', 'organization_unit', 'role'))");
            DB::statement("ALTER TABLE public_feedback_routing_rules ADD CONSTRAINT pfrr_target_type_chk CHECK (target_type IS NULL OR target_type IN ('employee', 'organization'))");
            DB::statement("ALTER TABLE public_feedback_routing_rules ADD CONSTRAINT pfrr_submission_type_chk CHECK (submission_type IS NULL OR submission_type IN ('suggestion', 'complaint'))");
            DB::statement('ALTER TABLE public_feedback_routing_rules ADD CONSTRAINT pfrr_effective_window_chk CHECK (effective_from IS NULL OR effective_to IS NULL OR effective_to >= effective_from)');
            DB::statement("ALTER TABLE public_feedback_assignments ADD CONSTRAINT pfa_handler_type_chk CHECK (handler_type IN ('user', 'organization_unit', 'role'))");
        }

        if (DB::pretending()) {
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
        Schema::dropIfExists('public_feedback_status_history');
        Schema::dropIfExists('public_feedback_assignments');
        Schema::dropIfExists('public_feedback_routing_rules');
    }
};
