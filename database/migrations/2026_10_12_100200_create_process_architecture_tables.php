<?php

declare(strict_types=1);

use App\Support\Database\SchemaResume;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organization Design & Workforce Planning, phase 3
 * (docs/organization-design-workforce-planning-plan.md §3.3): the master
 * process architecture an institution designs for each service, and the
 * generalisation of work standards so they measure master tasks and
 * activities as well as position tasks.
 *
 *   processes            — a service's process, versioned by lineage like
 *                          mandates and services; the version in force is
 *                          the published one.
 *   process_activities   — the activity tree of one process version
 *                          (parent_activity_id, materialised path).
 *   process_tasks        — the tasks of an activity, in sequence.
 *   work_item_ios        — typed inputs / outputs / beneficiaries /
 *                          dependencies of a process, activity or task.
 *   position_service_task_standards gains master_task_id and activity_id:
 *                          exactly one of (task_id, master_task_id,
 *                          activity_id) is set per standard row.
 *   position_service_tasks.master_task_id — the bridge from Daily Work's
 *                          position tasks to the master design; Daily Work
 *                          keeps measuring against the position task's own
 *                          standards.
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaResume::create('processes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('service_id')->constrained('services')->restrictOnDelete();
            $table->uuid('lineage_key');
            $table->unsignedInteger('version_no')->default(1);
            $table->string('code', 40);
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->string('process_type', 20)->default('core');
            $table->foreignUuid('owner_organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['lineage_key', 'version_no'], 'processes_lineage_version_unique');
            $table->unique(['organization_id', 'code', 'version_no'], 'processes_org_code_version_unique');
            $table->index(['organization_id', 'status'], 'processes_org_status_idx');
            $table->index(['service_id', 'status'], 'processes_service_status_idx');
        });

        SchemaResume::create('process_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('process_id')->constrained('processes')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->uuid('parent_activity_id')->nullable();
            $table->string('code', 40);
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            // Materialised ancestry ("rootId/childId/…") so a subtree is one range query.
            $table->string('path', 1200)->default('');
            $table->unsignedSmallInteger('depth')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['process_id', 'code'], 'process_activities_process_code_unique');
            $table->index(['process_id', 'parent_activity_id', 'sequence'], 'process_activities_parent_idx');
            // MySQL/MariaDB cap an index key at 3072 bytes; the 1200-character utf8mb4 path
            // exceeds that, so the key covers a 700-character prefix of the path there.
            $table->index([
                'process_id',
                in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? DB::raw('`path`(700)') : 'path',
            ], 'process_activities_path_idx');
        });
        SchemaResume::table('process_activities', function (Blueprint $table): void {
            $table->foreign('parent_activity_id')->references('id')->on('process_activities')->restrictOnDelete();
        });

        SchemaResume::create('process_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('activity_id')->constrained('process_activities')->cascadeOnDelete();
            $table->foreignUuid('process_id')->constrained('processes')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->text('required_output')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['activity_id', 'code'], 'process_tasks_activity_code_unique');
            $table->index(['activity_id', 'sequence'], 'process_tasks_activity_sequence_idx');
            $table->index(['process_id'], 'process_tasks_process_idx');
        });

        SchemaResume::create('work_item_ios', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('subject_type', 80);
            $table->uuid('subject_id');
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('label_en');
            $table->string('label_am')->nullable();
            $table->text('description')->nullable();
            // A dependency may name the activity it waits on (same process).
            $table->foreignUuid('depends_on_activity_id')->nullable()->constrained('process_activities')->nullOnDelete();
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'sequence'], 'work_item_ios_subject_idx');
            $table->index(['organization_id', 'kind'], 'work_item_ios_org_kind_idx');
        });

        SchemaResume::table('position_service_task_standards', function (Blueprint $table): void {
            $table->uuid('task_id')->nullable()->change();
            $table->foreignUuid('master_task_id')->nullable()->after('task_id')->constrained('process_tasks')->restrictOnDelete();
            $table->foreignUuid('activity_id')->nullable()->after('master_task_id')->constrained('process_activities')->restrictOnDelete();

            $table->unique(['master_task_id', 'version_no'], 'psts_master_task_version_unique');
            $table->unique(['activity_id', 'version_no'], 'psts_activity_version_unique');
            $table->index(['master_task_id', 'status', 'effective_from', 'effective_to'], 'psts_master_resolve_idx');
            $table->index(['activity_id', 'status', 'effective_from', 'effective_to'], 'psts_activity_resolve_idx');
        });

        SchemaResume::table('position_service_tasks', function (Blueprint $table): void {
            $table->foreignUuid('master_task_id')->nullable()->after('organization_id')->constrained('process_tasks')->nullOnDelete();
            $table->index('master_task_id', 'pst_master_task_idx');
        });

        // PostgreSQL only, each once (SchemaResume::check).
        SchemaResume::check('processes', 'processes_dates_chk', 'effective_to IS NULL OR effective_to >= effective_from');
        SchemaResume::check('process_activities', 'process_activities_parent_chk', 'parent_activity_id IS NULL OR parent_activity_id <> id');
        SchemaResume::check('work_item_ios', 'work_item_ios_kind_chk', "kind IN ('input', 'output', 'beneficiary', 'dependency')");
        SchemaResume::check('position_service_task_standards', 'psts_one_subject_chk', '(task_id IS NOT NULL)::int + (master_task_id IS NOT NULL)::int + (activity_id IS NOT NULL)::int = 1');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE position_service_task_standards DROP CONSTRAINT IF EXISTS psts_one_subject_chk');
        }
        Schema::table('position_service_tasks', function (Blueprint $table): void {
            $table->dropForeign(['master_task_id']);
            $table->dropIndex('pst_master_task_idx');
            $table->dropColumn('master_task_id');
        });
        Schema::table('position_service_task_standards', function (Blueprint $table): void {
            $table->dropForeign(['master_task_id']);
            $table->dropForeign(['activity_id']);
            $table->dropUnique('psts_master_task_version_unique');
            $table->dropUnique('psts_activity_version_unique');
            $table->dropIndex('psts_master_resolve_idx');
            $table->dropIndex('psts_activity_resolve_idx');
            $table->dropColumn(['master_task_id', 'activity_id']);
        });
        Schema::dropIfExists('work_item_ios');
        Schema::dropIfExists('process_tasks');
        Schema::dropIfExists('process_activities');
        Schema::dropIfExists('processes');
    }
};
