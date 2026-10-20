<?php

declare(strict_types=1);

use App\Support\Database\SchemaResume;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organization Design & Workforce Planning, phase 8
 * (docs/organization-design-workforce-planning-plan.md §3.7): position
 * profiles and job descriptions.
 *
 *   position_profiles                   — purpose, reporting line and
 *                                         working conditions of a position,
 *                                         versioned by lineage (§61).
 *   position_work_links                 — the services / processes /
 *                                         activities / tasks a position is
 *                                         responsible for (§67–68).
 *   position_competency_requirements    — competencies a position needs,
 *                                         derived from the linked work or
 *                                         written by the designer (§69).
 *   position_qualification_requirements — typed qualification rows (§70).
 *   job_descriptions                    — one document per version: draft →
 *                                         under_review → approved →
 *                                         published → superseded (§64–66).
 *   job_description_sections            — derived sections regenerated from
 *                                         the masters, custom ones written.
 *   employee_assignments.job_description_id — the JD in force when the
 *                                         person was assigned (§66).
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaResume::create('position_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('position_id')->constrained('positions')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->uuid('lineage_key');
            $table->unsignedInteger('version_no')->default(1);
            $table->text('purpose_en')->nullable();
            $table->text('purpose_am')->nullable();
            $table->foreignUuid('reports_to_position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->text('working_conditions_en')->nullable();
            $table->text('working_conditions_am')->nullable();
            $table->text('authorities_en')->nullable();
            $table->text('authorities_am')->nullable();
            $table->text('relationships_en')->nullable();
            $table->text('relationships_am')->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['lineage_key', 'version_no'], 'position_profiles_lineage_version_unique');
            $table->index(['position_id', 'status'], 'position_profiles_position_status_idx');
        });

        SchemaResume::create('position_work_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('position_id')->constrained('positions')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('object_type', 20);
            $table->uuid('object_id');
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('sequence')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['position_id', 'object_type', 'object_id'], 'position_work_links_unique');
            $table->index(['object_type', 'object_id'], 'position_work_links_object_idx');
        });

        SchemaResume::create('position_competency_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('position_id')->constrained('positions')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('competency_id')->constrained('competencies')->restrictOnDelete();
            $table->foreignUuid('minimum_level_id')->constrained('competency_levels')->restrictOnDelete();
            $table->boolean('is_mandatory')->default(true);
            $table->string('source', 10)->default('manual');
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();

            $table->unique(['position_id', 'competency_id'], 'pcr_position_competency_unique');
        });

        SchemaResume::create('position_qualification_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('position_id')->constrained('positions')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('education_level_id')->constrained('education_levels')->restrictOnDelete();
            $table->string('field_of_study')->nullable();
            $table->string('certification')->nullable();
            $table->string('license')->nullable();
            $table->unsignedSmallInteger('experience_years')->default(0);
            $table->boolean('is_mandatory')->default(true);
            $table->string('source', 10)->default('manual');
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();

            $table->index(['position_id'], 'pqr_position_idx');
        });

        SchemaResume::create('job_descriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('position_id')->constrained('positions')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('position_profile_id')->constrained('position_profiles')->restrictOnDelete();
            $table->uuid('lineage_key');
            $table->unsignedInteger('version_no')->default(1);
            $table->string('reference_number', 40)->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_comment')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['lineage_key', 'version_no'], 'job_descriptions_lineage_version_unique');
            $table->index(['position_id', 'status'], 'job_descriptions_position_status_idx');
            $table->index(['organization_id', 'status'], 'job_descriptions_org_status_idx');
        });

        SchemaResume::create('job_description_sections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('job_description_id')->constrained('job_descriptions')->cascadeOnDelete();
            $table->string('section_key', 40);
            $table->string('source', 10);
            $table->unsignedInteger('sequence')->default(0);
            $table->string('title_en');
            $table->string('title_am')->nullable();
            $table->text('content_en')->nullable();
            $table->text('content_am')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['job_description_id', 'section_key'], 'jd_sections_unique');
        });

        // Plain column plus index everywhere; the foreign key only where it is
        // a native ALTER. On SQLite a foreign key on an existing table makes
        // Laravel rebuild the table, and the rebuild drops the WHERE clause of
        // the partial unique index employee_assignments_one_current_per_employee.
        SchemaResume::table('employee_assignments', function (Blueprint $table): void {
            $table->uuid('job_description_id')->nullable()->index('employee_assignments_job_description_idx');
            if (DB::getDriverName() !== 'sqlite') {
                $table->foreign('job_description_id')->references('id')->on('job_descriptions')->nullOnDelete();
            }
        });

        // PostgreSQL only, each once (SchemaResume::check).
        SchemaResume::check('position_work_links', 'position_work_links_type_chk', "object_type IN ('service', 'process', 'activity', 'task')");
        SchemaResume::check('job_description_sections', 'jd_sections_source_chk', "source IN ('derived', 'custom')");
        SchemaResume::check('position_profiles', 'position_profiles_dates_chk', 'effective_to IS NULL OR effective_to >= effective_from');
        SchemaResume::check('job_descriptions', 'job_descriptions_dates_chk', 'effective_to IS NULL OR effective_to >= effective_from');
    }

    public function down(): void
    {
        Schema::table('employee_assignments', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['job_description_id']);
            }
            $table->dropIndex('employee_assignments_job_description_idx');
            $table->dropColumn('job_description_id');
        });
        Schema::dropIfExists('job_description_sections');
        Schema::dropIfExists('job_descriptions');
        Schema::dropIfExists('position_qualification_requirements');
        Schema::dropIfExists('position_competency_requirements');
        Schema::dropIfExists('position_work_links');
        Schema::dropIfExists('position_profiles');
    }
};
