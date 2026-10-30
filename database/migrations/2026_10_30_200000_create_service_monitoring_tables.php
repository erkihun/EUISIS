<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Service Delivery Monitoring & Support (docs/service-monitoring.md): the
 * quarterly service-delivery report of every institution, its review and
 * consolidation, and the support function around it.
 *
 * Conventions shared with the other module migrations: UUID keys, explicit
 * index and foreign-key names under 64 characters (the live MySQL server
 * refuses longer generated ones), DATETIME for mandatory moments, and one
 * hasTable guard per table so an interrupted deployment can be resumed.
 * "One current revision" style rules are partial unique indexes on PostgreSQL
 * and SQLite, and a unique index on a VIRTUAL generated column on MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->create('service_monitoring_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->boolean('require_stamped_copy')->default(false);
            $table->text('declaration_text_en')->nullable();
            $table->text('declaration_text_am')->nullable();
            $table->foreignUuid('owner_organization_id')->nullable()->constrained('organizations', 'id', 'sm_set_owner_org_fk')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'sm_set_updated_by_fk')->nullOnDelete();
            $table->timestamps();
        });

        $this->create('service_monitoring_driver_occupations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('occupation_id')->constrained('occupations', 'id', 'sm_drv_occupation_fk')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'sm_drv_created_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->unique('occupation_id', 'sm_drv_occupation_uq');
        });

        $this->create('service_monitoring_fiscal_years', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedSmallInteger('ec_year');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'sm_fy_created_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->unique('ec_year', 'sm_fy_ec_year_uq');
        });

        $this->create('service_monitoring_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('fiscal_year_id')->constrained('service_monitoring_fiscal_years', 'id', 'sm_per_fy_fk')->cascadeOnDelete();
            $table->unsignedTinyInteger('quarter');
            $table->string('form_version', 20);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->date('opens_on');
            $table->date('submission_due_on');
            $table->date('subcity_review_due_on');
            $table->date('city_review_due_on');
            $table->string('status', 20)->default('scheduled');
            $table->dateTime('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users', 'id', 'sm_per_locked_by_fk')->nullOnDelete();
            $table->dateTime('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users', 'id', 'sm_per_reopened_by_fk')->nullOnDelete();
            $table->text('reopened_reason')->nullable();
            $table->timestamps();
            $table->unique(['fiscal_year_id', 'quarter'], 'sm_per_fy_quarter_uq');
            $table->index(['status', 'submission_due_on'], 'sm_per_status_due_idx');
        });

        $this->create('service_monitoring_participants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations', 'id', 'sm_par_org_fk')->restrictOnDelete();
            $table->string('level', 20);
            $table->foreignUuid('parent_participant_id')->nullable()->constrained('service_monitoring_participants', 'id', 'sm_par_parent_fk')->nullOnDelete();
            $table->date('active_from');
            $table->date('active_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'sm_par_created_by_fk')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'sm_par_updated_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->index(['level', 'active_to'], 'sm_par_level_idx');
            $table->index('parent_participant_id', 'sm_par_parent_idx');
        });
        $this->uniqueWhere('service_monitoring_participants', 'sm_par_one_active_uq', 'organization_id', 'active_to IS NULL', 'active_org_key', 'CHAR(36)');

        $this->create('service_monitoring_support_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('participant_id')->constrained('service_monitoring_participants', 'id', 'sm_sup_par_fk')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users', 'id', 'sm_sup_user_fk')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users', 'id', 'sm_sup_assigned_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->unique(['participant_id', 'user_id'], 'sm_sup_pair_uq');
            $table->index('user_id', 'sm_sup_user_idx');
        });

        $this->create('service_monitoring_extensions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('period_id')->constrained('service_monitoring_periods', 'id', 'sm_ext_period_fk')->cascadeOnDelete();
            $table->foreignUuid('participant_id')->constrained('service_monitoring_participants', 'id', 'sm_ext_par_fk')->cascadeOnDelete();
            $table->dateTime('due_at');
            $table->text('reason');
            $table->foreignId('granted_by')->nullable()->constrained('users', 'id', 'sm_ext_granted_by_fk')->nullOnDelete();
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users', 'id', 'sm_ext_revoked_by_fk')->nullOnDelete();
            $table->text('revoke_reason')->nullable();
            $table->timestamps();
            $table->index(['participant_id', 'period_id'], 'sm_ext_par_period_idx');
        });

        $this->create('service_monitoring_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('period_id')->constrained('service_monitoring_periods', 'id', 'sm_sub_period_fk')->restrictOnDelete();
            $table->foreignUuid('participant_id')->constrained('service_monitoring_participants', 'id', 'sm_sub_par_fk')->restrictOnDelete();
            // Snapshots at creation: a later registry change never rewrites a submitted report.
            $table->foreignUuid('organization_id')->constrained('organizations', 'id', 'sm_sub_org_fk')->restrictOnDelete();
            $table->string('level', 20);
            $table->string('form_type', 2);
            $table->string('form_version', 20);
            $table->unsignedSmallInteger('revision_no')->default(1);
            $table->boolean('is_current')->default(true);
            $table->string('status', 20)->default('draft');
            $table->foreignUuid('extension_id')->nullable()->constrained('service_monitoring_extensions', 'id', 'sm_sub_ext_fk')->nullOnDelete();
            $table->boolean('submitted_late')->default(false);
            $table->foreignUuid('supersedes_submission_id')->nullable()->constrained('service_monitoring_submissions', 'id', 'sm_sub_supersedes_fk')->nullOnDelete();
            $table->boolean('section_1_nothing')->default(false);
            $table->boolean('section_2_nothing')->default(false);
            $table->boolean('section_3_nothing')->default(false);
            $table->boolean('section_4_nothing')->default(false);
            $table->text('special_support_narrative')->nullable();
            $table->foreignId('attested_by')->nullable()->constrained('users', 'id', 'sm_sub_attested_by_fk')->nullOnDelete();
            $table->dateTime('attested_at')->nullable();
            $table->string('attester_name_snapshot', 255)->nullable();
            $table->string('attester_title_snapshot', 255)->nullable();
            $table->string('attester_organization_snapshot', 255)->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users', 'id', 'sm_sub_submitted_by_fk')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users', 'id', 'sm_sub_reviewed_by_fk')->nullOnDelete();
            $table->dateTime('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users', 'id', 'sm_sub_accepted_by_fk')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'sm_sub_created_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->unique(['period_id', 'participant_id', 'revision_no'], 'sm_sub_revision_uq');
            $table->index(['period_id', 'status'], 'sm_sub_period_status_idx');
            $table->index(['participant_id', 'period_id'], 'sm_sub_par_period_idx');
            $table->index('organization_id', 'sm_sub_org_idx');
        });
        $this->uniqueWhere('service_monitoring_submissions', 'sm_sub_one_current_uq', 'period_id, participant_id', $this->truthy('is_current'), 'current_key', 'VARCHAR(80)');
        $this->uniqueWhere('service_monitoring_submissions', 'sm_sub_one_accepted_uq', 'period_id, participant_id', "status = 'accepted'", 'accepted_key', 'VARCHAR(80)');

        $this->create('service_monitoring_submission_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('submission_id')->constrained('service_monitoring_submissions', 'id', 'sm_his_sub_fk')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users', 'id', 'sm_his_actor_fk')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->dateTime('created_at');
            $table->index(['submission_id', 'created_at'], 'sm_his_sub_idx');
        });

        $this->create('service_monitoring_accountability_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('submission_id')->constrained('service_monitoring_submissions', 'id', 'sm_acc_sub_fk')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('subject_type', 20);
            $table->foreignUuid('employee_id')->nullable()->constrained('employees', 'id', 'sm_acc_employee_fk')->nullOnDelete();
            // Encrypted at rest (EncryptedOrPlaintext): name, reason and action detail.
            $table->text('full_name')->nullable();
            $table->string('office_name', 255)->nullable();
            $table->string('role_category', 30);
            $table->string('reason_category', 30);
            $table->text('reason_detail');
            $table->string('final_action', 40);
            $table->text('action_detail');
            $table->date('action_date');
            $table->text('remark')->nullable();
            $table->string('source', 20)->default('manual');
            // A disciplinary decision of the Discipline module; referenced, never written.
            $table->uuid('source_decision_id')->nullable();
            $table->boolean('repeat_confirmed')->default(false);
            $table->timestamps();
            // NULLs are distinct in a unique index on every supported database, so
            // customers (no employee) and manual rows (no decision) are unaffected.
            $table->unique(['submission_id', 'employee_id'], 'sm_acc_sub_employee_uq');
            $table->unique(['submission_id', 'source_decision_id'], 'sm_acc_sub_decision_uq');
            $table->index(['employee_id', 'action_date'], 'sm_acc_employee_idx');
        });

        $this->create('service_monitoring_institution_building', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('submission_id')->constrained('service_monitoring_submissions', 'id', 'sm_ib_sub_fk')->cascadeOnDelete();
            foreach (['daycare_count', 'cafeteria_count', 'consumer_shop_count', 'waiting_area_count', 'waiting_area_capacity',
                'suggestion_box_count', 'led_count', 'signage_count', 'employees_excl_drivers', 'chest_badges_excl_drivers',
                'desk_badges', 'awareness_trainings', 'awareness_participants'] as $column) {
                $table->unsignedInteger($column)->nullable();
            }
            $table->timestamps();
            $table->unique('submission_id', 'sm_ib_sub_uq');
        });

        $this->create('service_monitoring_prefill_values', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('submission_id')->constrained('service_monitoring_submissions', 'id', 'sm_pre_sub_fk')->cascadeOnDelete();
            $table->string('field', 40);
            $table->unsignedInteger('system_value')->nullable();
            $table->string('source', 60);
            $table->string('notice', 60)->nullable();
            $table->dateTime('captured_at');
            $table->text('override_reason')->nullable();
            $table->timestamps();
            $table->unique(['submission_id', 'field'], 'sm_pre_sub_field_uq');
        });

        $this->create('service_monitoring_digital_services', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('submission_id')->constrained('service_monitoring_submissions', 'id', 'sm_dig_sub_fk')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->foreignUuid('service_id')->nullable()->constrained('services', 'id', 'sm_dig_service_fk')->nullOnDelete();
            $table->foreignUuid('parent_entry_id')->nullable()->constrained('service_monitoring_digital_services', 'id', 'sm_dig_parent_fk')->cascadeOnDelete();
            $table->string('level', 10);
            $table->string('service_name_am', 255)->nullable();
            $table->string('service_name_en', 255)->nullable();
            $table->boolean('is_catalog_gap')->default(false);
            $table->string('technology_type', 30);
            $table->string('technology_other', 160)->nullable();
            $table->string('system_name', 255);
            $table->unsignedSmallInteger('system_count')->default(1);
            $table->date('go_live_on');
            $table->text('remark')->nullable();
            $table->string('verification_status', 20)->default('pending');
            $table->foreignId('verified_by')->nullable()->constrained('users', 'id', 'sm_dig_verified_by_fk')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['submission_id', 'verification_status'], 'sm_dig_sub_status_idx');
            $table->index('verification_status', 'sm_dig_status_idx');
        });

        $this->create('service_monitoring_service_totals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('submission_id')->constrained('service_monitoring_submissions', 'id', 'sm_tot_sub_fk')->cascadeOnDelete();
            $table->unsignedInteger('main_total')->nullable();
            $table->unsignedInteger('sub_total')->nullable();
            $table->unsignedInteger('catalog_main_total')->nullable();
            $table->timestamps();
            $table->unique('submission_id', 'sm_tot_sub_uq');
        });

        $this->create('service_monitoring_standard_performance', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('submission_id')->constrained('service_monitoring_submissions', 'id', 'sm_std_sub_fk')->cascadeOnDelete();
            foreach (['main_above', 'main_at', 'main_below', 'sub_above', 'sub_at', 'sub_below'] as $column) {
                $table->unsignedInteger($column)->nullable();
            }
            $table->text('below_reasons')->nullable();
            $table->text('actions_taken')->nullable();
            $table->timestamps();
            $table->unique('submission_id', 'sm_std_sub_uq');
        });

        $this->create('service_monitoring_review_comments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('submission_id')->constrained('service_monitoring_submissions', 'id', 'sm_rc_sub_fk')->cascadeOnDelete();
            $table->unsignedTinyInteger('section');
            $table->string('line_reference', 80)->nullable();
            $table->text('body');
            $table->foreignId('author_id')->nullable()->constrained('users', 'id', 'sm_rc_author_fk')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users', 'id', 'sm_rc_resolved_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->index(['submission_id', 'section'], 'sm_rc_sub_idx');
        });

        $this->create('service_monitoring_correction_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('submission_id')->constrained('service_monitoring_submissions', 'id', 'sm_cor_sub_fk')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users', 'id', 'sm_cor_requested_by_fk')->nullOnDelete();
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users', 'id', 'sm_cor_decided_by_fk')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignUuid('new_submission_id')->nullable()->constrained('service_monitoring_submissions', 'id', 'sm_cor_new_sub_fk')->nullOnDelete();
            $table->timestamps();
            $table->index(['submission_id', 'status'], 'sm_cor_sub_status_idx');
        });

        $this->create('service_monitoring_consolidation_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('period_id')->constrained('service_monitoring_periods', 'id', 'sm_snap_period_fk')->restrictOnDelete();
            $table->string('kind', 30);
            // The certifying sub-city; null for the city-wide snapshot taken at lock.
            $table->foreignUuid('participant_id')->nullable()->constrained('service_monitoring_participants', 'id', 'sm_snap_par_fk')->restrictOnDelete();
            $table->longText('payload');
            $table->string('checksum', 64);
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'sm_snap_created_by_fk')->nullOnDelete();
            $table->dateTime('created_at');
            $table->index(['period_id', 'kind', 'created_at'], 'sm_snap_period_kind_idx');
        });

        $this->create('service_monitoring_certifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('period_id')->constrained('service_monitoring_periods', 'id', 'sm_cert_period_fk')->cascadeOnDelete();
            $table->foreignUuid('participant_id')->constrained('service_monitoring_participants', 'id', 'sm_cert_par_fk')->cascadeOnDelete();
            $table->foreignId('certified_by')->nullable()->constrained('users', 'id', 'sm_cert_by_fk')->nullOnDelete();
            $table->dateTime('certified_at');
            $table->foreignUuid('snapshot_id')->nullable()->constrained('service_monitoring_consolidation_snapshots', 'id', 'sm_cert_snapshot_fk')->nullOnDelete();
            $table->timestamps();
            $table->unique(['period_id', 'participant_id'], 'sm_cert_period_par_uq');
        });

        $this->create('service_monitoring_non_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('period_id')->constrained('service_monitoring_periods', 'id', 'sm_non_period_fk')->cascadeOnDelete();
            $table->foreignUuid('participant_id')->constrained('service_monitoring_participants', 'id', 'sm_non_par_fk')->cascadeOnDelete();
            $table->text('reason');
            $table->foreignId('recorded_by')->nullable()->constrained('users', 'id', 'sm_non_recorded_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->unique(['period_id', 'participant_id'], 'sm_non_period_par_uq');
        });

        $this->create('service_monitoring_clarifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('participant_id')->constrained('service_monitoring_participants', 'id', 'sm_cla_par_fk')->cascadeOnDelete();
            $table->foreignUuid('period_id')->nullable()->constrained('service_monitoring_periods', 'id', 'sm_cla_period_fk')->nullOnDelete();
            $table->foreignUuid('submission_id')->nullable()->constrained('service_monitoring_submissions', 'id', 'sm_cla_sub_fk')->nullOnDelete();
            $table->unsignedTinyInteger('section')->nullable();
            $table->string('subject', 255);
            $table->string('status', 20)->default('open');
            $table->foreignId('opened_by')->nullable()->constrained('users', 'id', 'sm_cla_opened_by_fk')->nullOnDelete();
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users', 'id', 'sm_cla_closed_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->index(['participant_id', 'status'], 'sm_cla_par_status_idx');
        });

        $this->create('service_monitoring_clarification_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('clarification_id')->constrained('service_monitoring_clarifications', 'id', 'sm_clm_cla_fk')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users', 'id', 'sm_clm_author_fk')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['clarification_id', 'created_at'], 'sm_clm_cla_idx');
        });

        $this->create('service_monitoring_visits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('participant_id')->constrained('service_monitoring_participants', 'id', 'sm_vis_par_fk')->cascadeOnDelete();
            $table->foreignUuid('period_id')->nullable()->constrained('service_monitoring_periods', 'id', 'sm_vis_period_fk')->nullOnDelete();
            $table->date('visited_on');
            $table->text('purpose');
            $table->text('summary')->nullable();
            $table->string('status', 20)->default('planned');
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'sm_vis_created_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->index(['participant_id', 'visited_on'], 'sm_vis_par_idx');
        });

        $this->create('service_monitoring_visit_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('service_monitoring_visits', 'id', 'sm_vm_visit_fk')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users', 'id', 'sm_vm_user_fk')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['visit_id', 'user_id'], 'sm_vm_pair_uq');
        });

        $this->create('service_monitoring_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('service_monitoring_visits', 'id', 'sm_fin_visit_fk')->cascadeOnDelete();
            $table->unsignedTinyInteger('section')->nullable();
            $table->text('body');
            $table->string('severity', 10);
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'sm_fin_created_by_fk')->nullOnDelete();
            $table->timestamps();
        });

        $this->create('service_monitoring_action_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('service_monitoring_visits', 'id', 'sm_act_visit_fk')->cascadeOnDelete();
            $table->foreignUuid('finding_id')->nullable()->constrained('service_monitoring_findings', 'id', 'sm_act_finding_fk')->nullOnDelete();
            $table->string('title', 255);
            $table->foreignUuid('owner_organization_id')->constrained('organizations', 'id', 'sm_act_owner_org_fk')->restrictOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users', 'id', 'sm_act_owner_user_fk')->nullOnDelete();
            $table->date('due_on');
            $table->string('status', 20)->default('open');
            $table->text('completion_note')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users', 'id', 'sm_act_completed_by_fk')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'sm_act_created_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'due_on'], 'sm_act_status_due_idx');
            $table->index('owner_organization_id', 'sm_act_owner_org_idx');
        });

        $this->create('service_monitoring_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('attachable_type', 40);
            $table->uuid('attachable_id');
            $table->string('kind', 30);
            $table->string('disk', 40);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users', 'id', 'sm_att_uploaded_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->index(['attachable_type', 'attachable_id'], 'sm_att_owner_idx');
        });

        $this->create('service_monitoring_reminder_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('participant_id')->constrained('service_monitoring_participants', 'id', 'sm_rem_par_fk')->cascadeOnDelete();
            $table->foreignUuid('period_id')->nullable()->constrained('service_monitoring_periods', 'id', 'sm_rem_period_fk')->cascadeOnDelete();
            $table->string('type', 30);
            $table->date('sent_on');
            // The period id, or the action item id for overdue action items: the idempotency subject.
            $table->string('subject_key', 64);
            $table->unsignedInteger('recipients')->default(0);
            $table->timestamps();
            $table->unique(['participant_id', 'subject_key', 'type', 'sent_on'], 'sm_rem_once_uq');
        });

        $this->create('service_monitoring_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('requested_by')->nullable()->constrained('users', 'id', 'sm_exp_requested_by_fk')->nullOnDelete();
            $table->string('scope_type', 20);
            $table->uuid('scope_id')->nullable();
            $table->foreignUuid('fiscal_year_id')->constrained('service_monitoring_fiscal_years', 'id', 'sm_exp_fy_fk')->cascadeOnDelete();
            $table->unsignedTinyInteger('quarter');
            $table->string('range', 20);
            $table->string('format', 10);
            $table->string('locale', 5);
            $table->boolean('named')->default(false);
            $table->string('status', 20)->default('queued');
            $table->string('disk', 40)->nullable();
            $table->string('path', 500)->nullable();
            $table->string('filename', 255)->nullable();
            $table->text('error')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->index(['requested_by', 'created_at'], 'sm_exp_requester_idx');
        });
    }

    public function down(): void
    {
        foreach ([
            'service_monitoring_exports', 'service_monitoring_reminder_logs', 'service_monitoring_attachments',
            'service_monitoring_action_items', 'service_monitoring_findings', 'service_monitoring_visit_members',
            'service_monitoring_visits', 'service_monitoring_clarification_messages', 'service_monitoring_clarifications',
            'service_monitoring_non_submissions', 'service_monitoring_certifications', 'service_monitoring_consolidation_snapshots',
            'service_monitoring_correction_requests', 'service_monitoring_review_comments', 'service_monitoring_standard_performance',
            'service_monitoring_service_totals', 'service_monitoring_digital_services', 'service_monitoring_prefill_values',
            'service_monitoring_institution_building', 'service_monitoring_accountability_entries', 'service_monitoring_submission_histories',
            'service_monitoring_submissions', 'service_monitoring_extensions', 'service_monitoring_support_assignments',
            'service_monitoring_participants', 'service_monitoring_periods', 'service_monitoring_fiscal_years',
            'service_monitoring_driver_occupations', 'service_monitoring_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function create(string $table, Closure $definition): void
    {
        if (! Schema::hasTable($table)) {
            Schema::create($table, $definition);
        }
    }

    private function truthy(string $column): string
    {
        return $column.' = '.(DB::getDriverName() === 'pgsql' ? 'true' : '1');
    }

    /**
     * A unique index over $columns for the rows matching $where: a partial index
     * on PostgreSQL and SQLite; on MySQL a VIRTUAL generated column holding the
     * key only for matching rows (never STORED: the live server refuses a stored
     * column over foreign-keyed columns), with a plain unique index on it.
     */
    private function uniqueWhere(string $table, string $name, string $columns, string $where, string $mysqlColumn, string $mysqlType): void
    {
        if (Schema::hasIndex($table, $name)) {
            return;
        }
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("CREATE UNIQUE INDEX {$name} ON {$table} ({$columns}) WHERE {$where}");

            return;
        }
        $parts = array_map('trim', explode(',', $columns));
        $key = count($parts) === 1 ? $parts[0] : 'CONCAT('.implode(", '|', ", $parts).')';
        if (! Schema::hasColumn($table, $mysqlColumn)) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN {$mysqlColumn} {$mysqlType} GENERATED ALWAYS AS (CASE WHEN {$where} THEN {$key} ELSE NULL END) VIRTUAL");
        }
        DB::statement("CREATE UNIQUE INDEX {$name} ON {$table} ({$mysqlColumn})");
    }
};
