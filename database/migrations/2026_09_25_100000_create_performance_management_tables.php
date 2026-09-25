<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Employee Performance Management System (EPMS) — docs/epms-architecture.md.
 *
 * Reuses organizations, organization_units, positions, employees,
 * employee_assignments, users, daily_activity_items, audit_logs, the
 * notification table, private storage and grievance_committees. Adds only the
 * performance domain.
 *
 * Scores are DECIMAL (never float). Snapshots use jsonb on PostgreSQL.
 * Uniqueness that must only hold for "live" rows uses a nullable *_key column
 * (NULLs never collide), which works identically on PostgreSQL and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_cycles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name_en', 255);
            $table->string('name_am', 255)->nullable();
            // Null = applies to every organization (city-wide cycle).
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->date('planning_start_date')->nullable();
            $table->date('planning_end_date')->nullable();
            $table->date('midyear_review_start_date')->nullable();
            $table->date('midyear_review_end_date')->nullable();
            $table->date('yearend_review_start_date')->nullable();
            $table->date('yearend_review_end_date')->nullable();
            $table->string('status', 32)->default('DRAFT');
            $table->boolean('is_current')->default(false);
            // One current cycle per organization scope ("global" for null); NULL when not current.
            $table->string('current_key', 64)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'start_date', 'end_date'], 'pcy_status_dates_idx');
            $table->index('organization_id', 'pcy_org_idx');
        });

        Schema::create('kpis', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name_en', 255);
            $table->string('name_am', 255)->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            // Null = city-wide library entry; otherwise owned by an organization.
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('measurement_type', 32);
            $table->string('unit_of_measure', 64)->nullable();
            $table->string('direction', 32);
            $table->string('aggregation_method', 32);
            $table->string('data_source_type', 32)->default('MANUAL');
            // For SYSTEM_TRANSACTION: a key registered in SystemKpiSourceRegistry.
            $table->string('system_source_key', 100)->nullable();
            // Documentation of the formula; never executed as code.
            $table->text('calculation_formula')->nullable();
            $table->decimal('baseline', 18, 4)->nullable();
            $table->string('frequency', 32)->default('ANNUAL');
            $table->boolean('allow_overachievement')->default(true);
            $table->decimal('achievement_cap', 10, 4)->nullable();
            // TARGET_IS_BEST: allowed deviation (same unit as the value) scoring 100%.
            $table->decimal('target_tolerance', 18, 4)->nullable();
            // TARGET_IS_BEST: deviation at which achievement reaches 0 (linear in between).
            $table->decimal('zero_score_deviation', 18, 4)->nullable();
            // MILESTONE: [{key, label_en, label_am, percent, requires_verification}]
            $table->jsonb('milestones')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['code', 'is_active'], 'kpi_code_active_idx');
            $table->index(['organization_id', 'is_active'], 'kpi_org_active_idx');
        });

        Schema::create('performance_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cycle_id')->constrained('performance_cycles')->restrictOnDelete();
            $table->string('plan_type', 32);
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->restrictOnDelete();
            $table->foreignUuid('position_id')->nullable()->constrained('positions')->restrictOnDelete();
            $table->foreignUuid('parent_plan_id')->nullable()->constrained('performance_plans')->nullOnDelete();
            // Shared by every version of the same plan.
            $table->uuid('lineage_key');
            $table->unsignedInteger('version_no')->default(1);
            $table->foreignUuid('supersedes_plan_id')->nullable()->constrained('performance_plans')->nullOnDelete();
            $table->string('title', 255);
            $table->string('status', 32)->default('DRAFT');
            // One live (non-superseded, non-closed) version per lineage.
            $table->string('live_key', 64)->nullable()->unique();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->text('change_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamps();

            $table->unique(['lineage_key', 'version_no'], 'pp_lineage_version_unique');
            $table->index(['cycle_id', 'organization_id', 'organization_unit_id', 'plan_type'], 'pp_cycle_scope_idx');
            $table->index(['position_id', 'cycle_id'], 'pp_position_cycle_idx');
            $table->index('parent_plan_id', 'pp_parent_idx');
        });

        Schema::create('performance_objectives', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('performance_plan_id')->constrained('performance_plans')->cascadeOnDelete();
            // The parent-plan objective this one derives from (cascade lineage).
            $table->foreignUuid('parent_objective_id')->nullable()->constrained('performance_objectives')->nullOnDelete();
            // The same objective in the previous version of this plan (version lineage).
            $table->foreignUuid('source_objective_id')->nullable()->constrained('performance_objectives')->nullOnDelete();
            $table->string('code', 50);
            $table->string('title_en', 500);
            $table->string('title_am', 500)->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->string('objective_type', 32);
            $table->string('cascade_mode', 32)->default('LOCAL_ONLY');
            $table->boolean('is_mandatory')->default(false);
            $table->decimal('weight', 7, 4)->default(0);
            $table->unsignedSmallInteger('priority')->default(0);
            $table->string('owner_type', 32)->nullable();
            $table->string('owner_id', 64)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            // ACTIVE or REJECTED (optional parent objective declined with a reason).
            $table->string('status', 32)->default('ACTIVE');
            $table->text('rejection_reason')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['performance_plan_id', 'code'], 'po_plan_code_unique');
            $table->index('performance_plan_id', 'po_plan_idx');
            $table->index('parent_objective_id', 'po_parent_idx');
        });

        Schema::create('performance_cascades', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cycle_id')->constrained('performance_cycles')->cascadeOnDelete();
            $table->foreignUuid('parent_plan_id')->constrained('performance_plans')->cascadeOnDelete();
            $table->foreignUuid('child_plan_id')->constrained('performance_plans')->cascadeOnDelete();
            $table->foreignUuid('parent_objective_id')->constrained('performance_objectives')->cascadeOnDelete();
            $table->foreignUuid('child_objective_id')->constrained('performance_objectives')->cascadeOnDelete();
            $table->string('source_level', 32);
            $table->string('target_level', 32);
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->foreignUuid('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->string('cascade_type', 32);
            $table->decimal('contribution_weight', 7, 4)->nullable();
            $table->string('aggregation_rule', 32)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['parent_objective_id', 'child_objective_id'], 'pc_parent_child_unique');
            $table->index('child_plan_id', 'pc_child_plan_idx');
        });

        Schema::create('kpi_targets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('kpi_id')->constrained('kpis')->restrictOnDelete();
            $table->foreignUuid('performance_plan_id')->constrained('performance_plans')->cascadeOnDelete();
            $table->foreignUuid('objective_id')->constrained('performance_objectives')->cascadeOnDelete();
            // The parent-plan target this one contributes to (aggregation lineage).
            $table->foreignUuid('parent_target_id')->nullable()->constrained('kpi_targets')->nullOnDelete();
            // Amendment chain: the target this version replaces.
            $table->foreignUuid('amended_from_id')->nullable()->constrained('kpi_targets')->nullOnDelete();
            $table->unsignedInteger('version_no')->default(1);
            $table->boolean('is_current')->default(true);
            $table->string('period_type', 32)->default('ANNUAL');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('baseline_value', 18, 4)->nullable();
            $table->decimal('target_value', 18, 4)->nullable();
            $table->decimal('target_numerator', 18, 4)->nullable();
            $table->decimal('target_denominator', 18, 4)->nullable();
            // Share of the objective (targets under one objective total 100).
            $table->decimal('weight', 7, 4)->default(0);
            $table->decimal('achievement_cap', 10, 4)->nullable();
            $table->decimal('tolerance', 18, 4)->nullable();
            $table->decimal('zero_score_deviation', 18, 4)->nullable();
            $table->decimal('minimum_acceptable_value', 18, 4)->nullable();
            $table->decimal('stretch_target', 18, 4)->nullable();
            $table->date('effective_from')->nullable();
            $table->text('amendment_reason')->nullable();
            $table->timestamps();

            $table->index(['kpi_id', 'performance_plan_id'], 'kt_kpi_plan_idx');
            $table->index('objective_id', 'kt_objective_idx');
            $table->index('parent_target_id', 'kt_parent_idx');
        });

        Schema::create('employee_performance_agreements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cycle_id')->constrained('performance_cycles')->restrictOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('employee_assignment_id')->constrained('employee_assignments')->restrictOnDelete();
            // The position plan it was derived from (nullable when none exists).
            $table->foreignUuid('performance_plan_id')->nullable()->constrained('performance_plans')->nullOnDelete();
            // Snapshots: where the work is done, frozen at creation (transfer-safe).
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->foreignUuid('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('agreement_version')->default(1);
            $table->foreignUuid('supersedes_agreement_id')->nullable()->constrained('employee_performance_agreements')->nullOnDelete();
            $table->boolean('is_temporary')->default(false);
            $table->string('status', 32)->default('DRAFT');
            // "employee:assignment:cycle" while live; NULL once closed/superseded.
            $table->string('active_key', 150)->nullable()->unique();
            $table->date('effective_from');
            $table->date('effective_to');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('employee_acknowledged_at')->nullable();
            $table->timestamp('manager_approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->text('close_reason')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'employee_assignment_id', 'cycle_id', 'agreement_version'], 'epa_version_unique');
            $table->index(['employee_id', 'cycle_id'], 'epa_employee_cycle_idx');
            $table->index(['cycle_id', 'organization_id', 'organization_unit_id', 'status'], 'epa_scope_idx');
            $table->index(['manager_user_id', 'status'], 'epa_manager_idx');
        });

        Schema::create('employee_performance_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agreement_id')->constrained('employee_performance_agreements')->cascadeOnDelete();
            $table->foreignUuid('objective_id')->nullable()->constrained('performance_objectives')->nullOnDelete();
            $table->foreignUuid('kpi_id')->constrained('kpis')->restrictOnDelete();
            // The position-plan target it adapts (lineage + aggregation into the unit).
            $table->foreignUuid('position_target_id')->nullable()->constrained('kpi_targets')->nullOnDelete();
            // Target amendment chain inside the agreement.
            $table->foreignUuid('supersedes_item_id')->nullable()->constrained('employee_performance_items')->nullOnDelete();
            $table->boolean('is_current')->default(true);
            $table->string('expected_output', 500);
            $table->decimal('weight', 7, 4);
            $table->decimal('baseline_value', 18, 4)->nullable();
            $table->decimal('target_value', 18, 4)->nullable();
            $table->decimal('target_numerator', 18, 4)->nullable();
            $table->decimal('target_denominator', 18, 4)->nullable();
            $table->decimal('achievement_cap', 10, 4)->nullable();
            $table->decimal('tolerance', 18, 4)->nullable();
            $table->decimal('zero_score_deviation', 18, 4)->nullable();
            $table->string('period_type', 32)->default('ANNUAL');
            $table->string('data_source_type', 32)->default('MANUAL');
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('is_additional')->default(false);
            $table->date('effective_from')->nullable();
            $table->text('amendment_reason')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['agreement_id', 'is_current'], 'epi_agreement_idx');
            $table->index('kpi_id', 'epi_kpi_idx');
            $table->index('position_target_id', 'epi_position_target_idx');
        });

        Schema::create('kpi_actuals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('kpi_id')->constrained('kpis')->restrictOnDelete();
            // Exactly one subject: a plan target or an employee agreement item.
            $table->foreignUuid('target_id')->nullable()->constrained('kpi_targets')->cascadeOnDelete();
            $table->foreignUuid('employee_performance_item_id')->nullable()->constrained('employee_performance_items')->cascadeOnDelete();
            $table->string('subject_key', 80); // "target:{id}" | "item:{id}"
            $table->foreignUuid('performance_plan_id')->nullable()->constrained('performance_plans')->nullOnDelete();
            $table->foreignUuid('agreement_id')->nullable()->constrained('employee_performance_agreements')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('actual_value', 18, 4)->nullable();
            $table->decimal('actual_numerator', 18, 4)->nullable();
            $table->decimal('actual_denominator', 18, 4)->nullable();
            $table->string('milestone_key', 64)->nullable();
            $table->string('source_type', 32);
            // Dedupe key per source: "manual", "daily_activity", "system:id_cards.issued", "aggregate".
            $table->string('source_key', 100);
            $table->string('source_reference_type', 100)->nullable();
            $table->string('source_reference_id', 64)->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            // One actual per subject, period and source: the double-entry guard.
            $table->unique(['subject_key', 'period_start', 'period_end', 'source_key'], 'ka_subject_period_source_unique');
            $table->index(['kpi_id', 'period_start', 'period_end'], 'ka_kpi_period_idx');
            $table->index(['organization_id', 'organization_unit_id'], 'ka_scope_idx');
            $table->index('agreement_id', 'ka_agreement_idx');
        });

        Schema::create('kpi_contributions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('kpi_id')->constrained('kpis')->restrictOnDelete();
            // The aggregate target receiving the contribution.
            $table->foreignUuid('parent_target_id')->constrained('kpi_targets')->cascadeOnDelete();
            $table->string('contributor_type', 16); // EMPLOYEE | UNIT | SYSTEM
            $table->string('contributor_id', 64);
            // The child actual consumed — each consumed at most once per parent.
            $table->foreignUuid('source_actual_id')->constrained('kpi_actuals')->cascadeOnDelete();
            $table->decimal('contribution_value', 18, 4)->nullable();
            $table->decimal('numerator', 18, 4)->nullable();
            $table->decimal('denominator', 18, 4)->nullable();
            $table->decimal('weight', 18, 4)->nullable();
            $table->string('source_type', 32);
            $table->string('source_reference', 150)->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamps();

            $table->unique(['parent_target_id', 'source_actual_id'], 'kc_parent_source_unique');
            $table->index(['parent_target_id', 'period_start', 'period_end'], 'kc_parent_period_idx');
        });

        Schema::create('performance_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agreement_id')->constrained('employee_performance_agreements')->cascadeOnDelete();
            $table->foreignUuid('employee_performance_item_id')->nullable()->constrained('employee_performance_items')->nullOnDelete();
            $table->foreignUuid('kpi_id')->nullable()->constrained('kpis')->nullOnDelete();
            $table->foreignUuid('daily_activity_item_id')->nullable()->constrained('daily_activity_items')->nullOnDelete();
            $table->string('evidence_type', 32);
            $table->string('title', 255);
            $table->text('description')->nullable();
            // Private disk only.
            $table->string('file_path', 500)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('source_reference', 150)->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->boolean('verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['agreement_id', 'employee_performance_item_id'], 'pe_agreement_item_idx');
        });

        Schema::create('performance_checkins', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agreement_id')->constrained('employee_performance_agreements')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('checkin_date');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->text('employee_summary')->nullable();
            $table->text('manager_comment')->nullable();
            // Confidential: never shown to the employee.
            $table->text('manager_private_note')->nullable();
            $table->string('progress_status', 32)->default('ON_TRACK');
            $table->text('blockers')->nullable();
            $table->text('support_required')->nullable();
            $table->text('learning_needs')->nullable();
            $table->text('next_actions')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['agreement_id', 'checkin_date'], 'pci_agreement_date_idx');
        });

        Schema::create('performance_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agreement_id')->constrained('employee_performance_agreements')->cascadeOnDelete();
            $table->string('review_type', 16);
            $table->string('status', 32)->default('DRAFT');
            $table->text('employee_self_assessment')->nullable();
            $table->text('achievements')->nullable();
            $table->text('challenges')->nullable();
            $table->text('contributions')->nullable();
            $table->text('development_needs')->nullable();
            $table->timestamp('employee_submitted_at')->nullable();
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('manager_comment')->nullable();
            $table->text('manager_private_note')->nullable();
            $table->jsonb('at_risk_item_ids')->nullable();
            $table->text('improvement_actions')->nullable();
            $table->timestamp('manager_reviewed_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['agreement_id', 'review_type'], 'pr_agreement_type_unique');
        });

        Schema::create('competency_frameworks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name_en', 255);
            $table->string('name_am', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('competencies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('framework_id')->constrained('competency_frameworks')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name_en', 255);
            $table->string('name_am', 255)->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['framework_id', 'code'], 'comp_framework_code_unique');
        });

        Schema::create('position_competencies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('position_id')->constrained('positions')->cascadeOnDelete();
            $table->foreignUuid('competency_id')->constrained('competencies')->cascadeOnDelete();
            $table->decimal('weight', 7, 4)->default(0);
            $table->timestamps();

            $table->unique(['position_id', 'competency_id'], 'poscomp_unique');
        });

        Schema::create('employee_competency_assessments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agreement_id')->constrained('employee_performance_agreements')->cascadeOnDelete();
            $table->foreignUuid('competency_id')->constrained('competencies')->restrictOnDelete();
            $table->decimal('weight', 7, 4)->default(0);
            $table->unsignedTinyInteger('self_rating')->nullable();
            $table->unsignedTinyInteger('manager_rating')->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('rated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rated_at')->nullable();
            $table->timestamps();

            $table->unique(['agreement_id', 'competency_id'], 'eca_agreement_competency_unique');
        });

        Schema::create('performance_rating_scales', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name_en', 255);
            $table->string('name_am', 255)->nullable();
            // RESULT (score bands) | COMPETENCY (1..N levels)
            $table->string('scale_type', 16);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('performance_rating_bands', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('scale_id')->constrained('performance_rating_scales')->cascadeOnDelete();
            $table->decimal('min_score', 10, 4)->nullable();
            $table->decimal('max_score', 10, 4)->nullable();
            // Competency level value (1..N); null for result bands.
            $table->unsignedTinyInteger('level_value')->nullable();
            $table->string('label_en', 100);
            $table->string('label_am', 100)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('scale_id', 'prb_scale_idx');
        });

        Schema::create('performance_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('cycle_id')->constrained('performance_cycles')->restrictOnDelete();
            $table->foreignUuid('agreement_id')->constrained('employee_performance_agreements')->restrictOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->unsignedInteger('revision_no')->default(1);
            $table->foreignUuid('supersedes_result_id')->nullable()->constrained('performance_results')->nullOnDelete();
            $table->boolean('is_current')->default(true);
            $table->decimal('results_score', 10, 4);
            $table->decimal('competency_score', 10, 4)->nullable();
            $table->decimal('results_weight', 7, 4);
            $table->decimal('competency_weight', 7, 4);
            // Formula output, before any approved adjustment/calibration.
            $table->decimal('calculated_score', 10, 4);
            $table->decimal('adjusted_score', 10, 4)->nullable();
            $table->decimal('calibrated_score', 10, 4)->nullable();
            $table->decimal('final_score', 10, 4);
            $table->foreignUuid('rating_scale_id')->nullable()->constrained('performance_rating_scales')->nullOnDelete();
            $table->foreignUuid('rating_band_id')->nullable()->constrained('performance_rating_bands')->nullOnDelete();
            $table->string('rating_label_en', 100)->nullable();
            $table->string('rating_label_am', 100)->nullable();
            $table->string('status', 32)->default('CALCULATED');
            $table->timestamp('calculated_at');
            $table->foreignId('calculated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            // Immutable once finalized: every input and every step.
            $table->jsonb('snapshot_json');
            $table->timestamps();

            $table->unique(['agreement_id', 'revision_no'], 'pres_agreement_revision_unique');
            $table->index(['employee_id', 'cycle_id'], 'pres_employee_cycle_idx');
            $table->index(['cycle_id', 'organization_id', 'status'], 'pres_scope_idx');
        });

        Schema::create('performance_score_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('result_id')->constrained('performance_results')->cascadeOnDelete();
            $table->string('adjustment_type', 16); // MANAGER | CALIBRATION | APPEAL
            $table->decimal('original_score', 10, 4);
            $table->decimal('adjusted_score', 10, 4);
            $table->text('reason');
            $table->string('status', 16)->default('PENDING');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['result_id', 'status'], 'psa_result_status_idx');
        });

        Schema::create('performance_calibration_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cycle_id')->constrained('performance_cycles')->restrictOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            // Panel membership comes from the reused grievance_committees model.
            $table->foreignUuid('committee_id')->nullable()->constrained('grievance_committees')->nullOnDelete();
            $table->string('title', 255);
            $table->date('session_date')->nullable();
            $table->string('status', 16)->default('DRAFT');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->index(['cycle_id', 'organization_id', 'status'], 'pcs_scope_idx');
        });

        Schema::create('performance_calibration_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('performance_calibration_sessions')->cascadeOnDelete();
            $table->foreignUuid('result_id')->constrained('performance_results')->restrictOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->decimal('manager_score', 10, 4);
            $table->decimal('proposed_score', 10, 4)->nullable();
            $table->decimal('calibrated_score', 10, 4)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'result_id'], 'pcal_session_result_unique');
        });

        Schema::create('performance_appeals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('appeal_no', 40)->unique();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('result_id')->constrained('performance_results')->restrictOnDelete();
            $table->foreignUuid('cycle_id')->constrained('performance_cycles')->restrictOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('committee_id')->nullable()->constrained('grievance_committees')->nullOnDelete();
            $table->text('reason');
            $table->string('attachment_path', 500)->nullable();
            $table->string('attachment_name', 255)->nullable();
            $table->timestamp('submitted_at');
            $table->string('status', 16)->default('SUBMITTED');
            $table->string('decision', 32)->nullable();
            $table->text('decision_reason')->nullable();
            $table->decimal('decided_score', 10, 4)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['cycle_id', 'organization_id', 'status'], 'pa_scope_idx');
            $table->index('employee_id', 'pa_employee_idx');
        });

        Schema::create('performance_improvement_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('agreement_id')->nullable()->constrained('employee_performance_agreements')->nullOnDelete();
            $table->foreignUuid('result_id')->nullable()->constrained('performance_results')->nullOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->text('identified_gap');
            $table->text('required_improvement');
            $table->text('support_action')->nullable();
            $table->text('training')->nullable();
            $table->text('manager_support')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->jsonb('review_dates')->nullable();
            $table->string('status', 16)->default('DRAFT');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'status'], 'pip_employee_idx');
        });

        Schema::create('individual_development_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('cycle_id')->nullable()->constrained('performance_cycles')->nullOnDelete();
            $table->foreignUuid('agreement_id')->nullable()->constrained('employee_performance_agreements')->nullOnDelete();
            $table->foreignUuid('competency_id')->nullable()->constrained('competencies')->nullOnDelete();
            $table->text('competency_gap')->nullable();
            $table->text('development_objective');
            $table->text('training')->nullable();
            $table->text('coaching')->nullable();
            $table->text('expected_outcome')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 16)->default('DRAFT');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'status'], 'idp_employee_idx');
        });

        Schema::create('performance_target_amendments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('subject_type', 16); // TARGET | ITEM
            $table->uuid('subject_id');
            $table->uuid('new_subject_id')->nullable();
            $table->jsonb('original_values');
            $table->jsonb('proposed_values');
            $table->text('reason');
            $table->date('effective_date');
            $table->string('status', 16)->default('PENDING');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'pta_subject_idx');
        });

        // Summary cache for dashboards (never the source of truth).
        Schema::create('performance_plan_scores', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('performance_plan_id')->constrained('performance_plans')->cascadeOnDelete();
            $table->foreignUuid('cycle_id')->constrained('performance_cycles')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->date('as_of');
            $table->decimal('score', 10, 4)->nullable();
            $table->jsonb('trace_json');
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique(['performance_plan_id', 'as_of'], 'pps_plan_asof_unique');
            $table->index(['cycle_id', 'organization_id'], 'pps_scope_idx');
        });

        // Daily Activity -> EPMS links (evidence only, never a score).
        Schema::table('daily_activity_items', function (Blueprint $table): void {
            $table->foreignUuid('performance_objective_id')->nullable()->after('kpi_id')->constrained('performance_objectives')->nullOnDelete();
            $table->foreignUuid('employee_performance_item_id')->nullable()->after('performance_objective_id')->constrained('employee_performance_items')->nullOnDelete();
            $table->index('employee_performance_item_id', 'dai_performance_item_idx');
        });

        $this->addPostgresChecks();
    }

    /** Range checks that PostgreSQL enforces at the database level. */
    private function addPostgresChecks(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'ALTER TABLE performance_cycles ADD CONSTRAINT pcy_dates_chk CHECK (end_date >= start_date)',
            'ALTER TABLE performance_objectives ADD CONSTRAINT po_weight_chk CHECK (weight >= 0 AND weight <= 100)',
            'ALTER TABLE kpi_targets ADD CONSTRAINT kt_weight_chk CHECK (weight >= 0 AND weight <= 100)',
            'ALTER TABLE kpi_targets ADD CONSTRAINT kt_period_chk CHECK (period_end >= period_start)',
            'ALTER TABLE employee_performance_items ADD CONSTRAINT epi_weight_chk CHECK (weight >= 0 AND weight <= 100)',
            'ALTER TABLE employee_performance_agreements ADD CONSTRAINT epa_dates_chk CHECK (effective_to >= effective_from)',
            'ALTER TABLE kpi_actuals ADD CONSTRAINT ka_subject_chk CHECK ((target_id IS NULL) <> (employee_performance_item_id IS NULL))',
            'ALTER TABLE performance_results ADD CONSTRAINT pres_score_chk CHECK (final_score >= 0 AND results_weight + competency_weight = 100)',
            'ALTER TABLE employee_competency_assessments ADD CONSTRAINT eca_rating_chk CHECK ((self_rating IS NULL OR self_rating BETWEEN 1 AND 10) AND (manager_rating IS NULL OR manager_rating BETWEEN 1 AND 10))',
        ] as $statement) {
            DB::statement($statement);
        }
    }

    public function down(): void
    {
        Schema::table('daily_activity_items', function (Blueprint $table): void {
            $table->dropForeign(['employee_performance_item_id']);
            $table->dropForeign(['performance_objective_id']);
            $table->dropIndex('dai_performance_item_idx');
            $table->dropColumn(['employee_performance_item_id', 'performance_objective_id']);
        });

        foreach ([
            'performance_plan_scores', 'performance_target_amendments', 'individual_development_plans',
            'performance_improvement_plans', 'performance_appeals', 'performance_calibration_items',
            'performance_calibration_sessions', 'performance_score_adjustments', 'performance_results',
            'performance_rating_bands', 'performance_rating_scales', 'employee_competency_assessments',
            'position_competencies', 'competencies', 'competency_frameworks', 'performance_reviews',
            'performance_checkins', 'performance_evidence', 'kpi_contributions', 'kpi_actuals',
            'employee_performance_items', 'employee_performance_agreements', 'kpi_targets',
            'performance_cascades', 'performance_objectives', 'performance_plans', 'kpis', 'performance_cycles',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
