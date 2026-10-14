<?php

declare(strict_types=1);

use App\Support\Database\SchemaResume;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization Design & Workforce Planning, phase 7
 * (docs/organization-design-workforce-planning-plan.md §3.6): manpower
 * estimation.
 *
 *   workforce_planning_methodologies — a whitelisted calculation strategy
 *                                      with schema-validated parameters and
 *                                      a rounding rule; versioned, city-level
 *                                      (no expressions, ever — §48).
 *   manpower_plans                   — an organization's plan for a period:
 *                                      draft → under_review → approved.
 *   manpower_plan_lines              — one estimate per unit (or proposed
 *                                      unit) × occupation × work item, with
 *                                      the inputs, the standard and the
 *                                      methodology version it used, the
 *                                      DECIMAL FTE and the calculation trace.
 *   position_requirements            — the roll-up by unit × occupation ×
 *                                      profession; implemented as positions
 *                                      and establishments, never assignments.
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaResume::create('workforce_planning_methodologies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('lineage_key');
            $table->unsignedInteger('version_no')->default(1);
            $table->string('code', 40);
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->string('calculation_type', 40);
            $table->json('parameters')->nullable();
            $table->string('rounding_rule', 20)->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['lineage_key', 'version_no'], 'wpm_lineage_version_unique');
            $table->unique(['code', 'version_no'], 'wpm_code_version_unique');
            $table->index(['calculation_type', 'status'], 'wpm_type_status_idx');
        });

        SchemaResume::create('manpower_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference_number', 32)->unique();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('hierarchy_version_id')->nullable()->constrained('hierarchy_versions')->nullOnDelete();
            $table->foreignUuid('study_id')->nullable()->constrained('organization_design_studies')->nullOnDelete();
            $table->foreignUuid('methodology_id')->constrained('workforce_planning_methodologies')->restrictOnDelete();
            $table->string('title_en');
            $table->string('title_am')->nullable();
            $table->text('notes')->nullable();
            $table->date('planning_period_start');
            $table->date('planning_period_end');
            $table->string('status', 20)->default('draft');
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_comment')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('implemented_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status'], 'manpower_plans_org_status_idx');
        });

        SchemaResume::create('manpower_plan_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('manpower_plans')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->restrictOnDelete();
            $table->foreignUuid('unit_design_proposal_id')->nullable()->constrained('unit_design_proposals')->restrictOnDelete();
            $table->foreignUuid('activity_id')->nullable()->constrained('process_activities')->nullOnDelete();
            $table->foreignUuid('task_id')->nullable()->constrained('process_tasks')->nullOnDelete();
            $table->foreignUuid('occupation_id')->constrained('occupations')->restrictOnDelete();
            $table->foreignUuid('profession_id')->nullable()->constrained('professions')->restrictOnDelete();
            $table->foreignUuid('methodology_id')->nullable()->constrained('workforce_planning_methodologies')->restrictOnDelete();
            $table->unsignedInteger('methodology_version_no')->nullable();
            $table->foreignUuid('standard_id')->nullable()->constrained('position_service_task_standards')->nullOnDelete();
            $table->json('inputs')->nullable();
            $table->decimal('calculated_fte', 12, 4)->nullable();
            $table->integer('recommended_headcount')->nullable();
            $table->integer('approved_headcount')->nullable();
            $table->text('adjustment_reason')->nullable();
            $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('calculation_trace')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->foreignUuid('position_requirement_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();

            $table->index(['plan_id', 'sequence'], 'mpl_plan_sequence_idx');
            $table->index(['organization_unit_id'], 'mpl_unit_idx');
            $table->index(['occupation_id'], 'mpl_occupation_idx');
        });

        SchemaResume::create('position_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('manpower_plans')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->restrictOnDelete();
            $table->foreignUuid('unit_design_proposal_id')->nullable()->constrained('unit_design_proposals')->restrictOnDelete();
            $table->foreignUuid('occupation_id')->constrained('occupations')->restrictOnDelete();
            $table->foreignUuid('profession_id')->nullable()->constrained('professions')->restrictOnDelete();
            $table->string('position_title_en');
            $table->string('position_title_am')->nullable();
            $table->string('grade_level', 50)->nullable();
            $table->string('position_type', 30)->default('permanent');
            $table->decimal('required_fte', 12, 4);
            $table->integer('approved_headcount')->nullable();
            $table->string('status', 20)->default('proposed');
            $table->foreignUuid('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignUuid('establishment_id')->nullable()->constrained('position_establishments')->nullOnDelete();
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamp('implemented_at')->nullable();
            $table->timestamps();

            $table->index(['plan_id', 'status'], 'position_requirements_plan_status_idx');
            $table->index(['organization_unit_id', 'occupation_id'], 'position_requirements_unit_occ_idx');
        });
        SchemaResume::table('manpower_plan_lines', function (Blueprint $table): void {
            $table->foreign('position_requirement_id')->references('id')->on('position_requirements')->nullOnDelete();
        });

        // PostgreSQL only, each once (SchemaResume::check).
        SchemaResume::check('workforce_planning_methodologies', 'wpm_type_chk', "calculation_type IN ('WORKLOAD_STANDARD_TIME', 'RATIO', 'FIXED_MINIMUM', 'SPAN_OF_CONTROL')");
        SchemaResume::check('workforce_planning_methodologies', 'wpm_rounding_chk', "rounding_rule IS NULL OR rounding_rule IN ('ceil', 'floor', 'half_up', 'manual')");
        SchemaResume::check('manpower_plans', 'manpower_plans_period_chk', 'planning_period_end >= planning_period_start');
        SchemaResume::check('manpower_plan_lines', 'mpl_one_subject_chk', '(organization_unit_id IS NOT NULL)::int + (unit_design_proposal_id IS NOT NULL)::int = 1');
        SchemaResume::check('position_requirements', 'position_requirements_one_subject_chk', '(organization_unit_id IS NOT NULL)::int + (unit_design_proposal_id IS NOT NULL)::int >= 1');
    }

    public function down(): void
    {
        Schema::table('manpower_plan_lines', function (Blueprint $table): void {
            $table->dropForeign(['position_requirement_id']);
        });
        Schema::dropIfExists('position_requirements');
        Schema::dropIfExists('manpower_plan_lines');
        Schema::dropIfExists('manpower_plans');
        Schema::dropIfExists('workforce_planning_methodologies');
    }
};
