<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organization Design & Workforce Planning, phase 4
 * (docs/organization-design-workforce-planning-plan.md §3.4): the people
 * side of the work design.
 *
 *   isco_groups                     — ISCO reference rows by revision
 *                                     (official dataset; D-12). Occupations
 *                                     keep their recorded codes and may cite
 *                                     a row.
 *   professions / specializations   — the profession and specialization
 *                                     ladder under an occupation (§28).
 *   competency_types                — CORE / TECHNICAL / … (§30).
 *   competency_levels               — named levels with a numeric rank so
 *                                     EPMS ratings stay comparable (D-13).
 *   education_levels                — the qualification ladder (§34).
 *   work_occupation_requirements    — who may do an activity or task.
 *   work_competency_requirements    — at what level, mandatory or not.
 *   work_qualification_requirements — typed qualification columns.
 *
 * Catalog rows referenced by a requirement cannot be deleted
 * (restrictOnDelete); they are deactivated instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('isco_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('revision', 20);
            $table->string('code', 10);
            $table->string('level', 20);
            $table->uuid('parent_id')->nullable();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['revision', 'code'], 'isco_groups_revision_code_unique');
            $table->index(['revision', 'parent_id'], 'isco_groups_parent_idx');
            $table->index(['revision', 'level'], 'isco_groups_level_idx');
        });
        Schema::table('isco_groups', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('isco_groups')->nullOnDelete();
        });

        Schema::table('occupations', function (Blueprint $table): void {
            $table->foreignUuid('isco_group_id')->nullable()->after('isco_code')->constrained('isco_groups')->nullOnDelete();
        });

        Schema::create('professions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('occupation_id')->constrained('occupations')->restrictOnDelete();
            $table->string('code', 40)->unique();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['occupation_id', 'is_active'], 'professions_occupation_active_idx');
        });

        Schema::create('specializations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('profession_id')->constrained('professions')->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['profession_id', 'code'], 'specializations_profession_code_unique');
        });

        Schema::create('competency_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('competencies', function (Blueprint $table): void {
            $table->foreignUuid('competency_type_id')->nullable()->after('framework_id')->constrained('competency_types')->restrictOnDelete();
        });

        Schema::create('competency_levels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            // Comparable to EPMS competency ratings (1–10).
            $table->unsignedTinyInteger('rank')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('education_levels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->unsignedTinyInteger('rank')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('work_occupation_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('activity_id')->nullable()->constrained('process_activities')->cascadeOnDelete();
            $table->foreignUuid('task_id')->nullable()->constrained('process_tasks')->cascadeOnDelete();
            $table->foreignUuid('occupation_id')->constrained('occupations')->restrictOnDelete();
            $table->foreignUuid('profession_id')->nullable()->constrained('professions')->restrictOnDelete();
            $table->foreignUuid('specialization_id')->nullable()->constrained('specializations')->restrictOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();

            $table->index(['activity_id'], 'wor_activity_idx');
            $table->index(['task_id'], 'wor_task_idx');
            $table->index(['occupation_id'], 'wor_occupation_idx');
        });

        Schema::create('work_competency_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('activity_id')->nullable()->constrained('process_activities')->cascadeOnDelete();
            $table->foreignUuid('task_id')->nullable()->constrained('process_tasks')->cascadeOnDelete();
            $table->foreignUuid('competency_id')->constrained('competencies')->restrictOnDelete();
            $table->foreignUuid('minimum_level_id')->constrained('competency_levels')->restrictOnDelete();
            $table->boolean('is_mandatory')->default(true);
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();

            $table->unique(['activity_id', 'competency_id'], 'wcr_activity_competency_unique');
            $table->unique(['task_id', 'competency_id'], 'wcr_task_competency_unique');
            $table->index(['competency_id'], 'wcr_competency_idx');
        });

        Schema::create('work_qualification_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('activity_id')->nullable()->constrained('process_activities')->cascadeOnDelete();
            $table->foreignUuid('task_id')->nullable()->constrained('process_tasks')->cascadeOnDelete();
            $table->foreignUuid('education_level_id')->constrained('education_levels')->restrictOnDelete();
            $table->string('field_of_study')->nullable();
            $table->string('certification')->nullable();
            $table->string('license')->nullable();
            $table->unsignedSmallInteger('experience_years')->default(0);
            $table->boolean('is_mandatory')->default(true);
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();

            $table->index(['activity_id'], 'wqr_activity_idx');
            $table->index(['task_id'], 'wqr_task_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (['work_occupation_requirements' => 'wor', 'work_competency_requirements' => 'wcr', 'work_qualification_requirements' => 'wqr'] as $tableName => $prefix) {
                DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$prefix}_one_subject_chk CHECK ((activity_id IS NOT NULL)::int + (task_id IS NOT NULL)::int = 1)");
            }
            DB::statement("ALTER TABLE isco_groups ADD CONSTRAINT isco_groups_level_chk CHECK (level IN ('major', 'sub_major', 'minor', 'unit'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('work_qualification_requirements');
        Schema::dropIfExists('work_competency_requirements');
        Schema::dropIfExists('work_occupation_requirements');
        Schema::dropIfExists('education_levels');
        Schema::dropIfExists('competency_levels');
        Schema::table('competencies', function (Blueprint $table): void {
            $table->dropForeign(['competency_type_id']);
            $table->dropColumn('competency_type_id');
        });
        Schema::dropIfExists('competency_types');
        Schema::dropIfExists('specializations');
        Schema::dropIfExists('professions');
        Schema::table('occupations', function (Blueprint $table): void {
            $table->dropForeign(['isco_group_id']);
            $table->dropColumn('isco_group_id');
        });
        Schema::dropIfExists('isco_groups');
    }
};
