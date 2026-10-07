<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee Daily Plan & Work Execution Register (የባለሞያ የእለት እቅድ ክንውን
 * መመዝገቢያ): the service structure below a position service.
 *
 *   Position → Position Service (ዋና አገልግሎት, existing)
 *            → Sub-Service (ንዑስ አገልግሎት)
 *            → Main Task (ዋና ተግባር)
 *            → Task Standard / BPR plan (ስታንዳርድ መለኪያ / የBPR ዕቅድ), versioned
 *
 * Master data only. Daily execution stays on daily_activity_items, which
 * snapshot the standard they were measured against. `organization_id` is
 * stored on every level, as on position_services, because it is the scope
 * boundary for administration and reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_service_sub_services', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('position_service_id')->constrained('position_services')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['position_service_id', 'code'], 'pss_service_code_unique');
            $table->index(['position_service_id', 'is_active'], 'pss_service_active_idx');
            $table->index('organization_id', 'pss_org_idx');
        });

        Schema::create('position_service_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('sub_service_id')->constrained('position_service_sub_services')->cascadeOnDelete();
            $table->foreignUuid('position_service_id')->constrained('position_services')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['sub_service_id', 'code'], 'pst_sub_code_unique');
            $table->index(['sub_service_id', 'is_active'], 'pst_sub_active_idx');
            $table->index('position_service_id', 'pst_service_idx');
            $table->index('organization_id', 'pst_org_idx');
        });

        /*
         * One row per version. An approved version is never edited: a change
         * is a new version, and approving it closes the previous one the day
         * before it takes effect. Daily items copy the planned values they
         * were measured against, so a later version never rescores history.
         *
         * A dimension applies only when its planned value is set. Planned
         * values are NUMERIC, never float: they are official denominators.
         */
        Schema::create('position_service_task_standards', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained('position_service_tasks')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('status', 20)->default('draft');

            // The standard measurement as written on the approved form.
            $table->string('standard_measure', 500)->nullable();
            // Reference of the approved BPR document or plan.
            $table->string('bpr_reference', 255)->nullable();

            $table->decimal('planned_quantity', 14, 4)->nullable();
            $table->string('quantity_unit', 64)->nullable();
            $table->decimal('planned_time_minutes', 14, 4)->nullable();
            $table->decimal('planned_quality', 14, 4)->nullable();
            $table->string('quality_unit', 64)->nullable();
            // How actual quality is measured, in the standard's own words.
            $table->text('quality_measure')->nullable();
            // Who records actual quality: the employee, or the reviewer.
            $table->string('quality_source', 20)->default('employee');

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'version_no'], 'psts_task_version_unique');
            // Resolves "the standard in force on a date" for a task.
            $table->index(['task_id', 'status', 'effective_from', 'effective_to'], 'psts_resolve_idx');
            $table->index(['organization_id', 'status'], 'psts_org_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_service_task_standards');
        Schema::dropIfExists('position_service_tasks');
        Schema::dropIfExists('position_service_sub_services');
    }
};
