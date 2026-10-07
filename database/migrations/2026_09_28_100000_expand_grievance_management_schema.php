<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Grievance Management — expand step (docs/grievance-management.md §3).
 *
 * Additive only. The first-generation tables (grievance_assignments,
 * grievance_responses, grievance_escalations, grievance_decision_letters,
 * grievance_sla_rules) stay in place and are backfilled into the new model by
 * 2026_09_28_100100; dropping them is a separate, later contraction.
 *
 * grievance_committees is shared with EPMS (performance_appeal /
 * performance_calibration panels), so its existing columns and the
 * status/role values EPMS reads are left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Existing tables: additive columns ───────────────────────────────
        // Each column and index is added only when missing. On MySQL a schema
        // change is not transactional, so a run that fails part-way leaves
        // what it already added; re-running must continue, not fail on it.
        $this->addColumns('grievances', [
            'employee_assignment_id' => fn (Blueprint $t) => $t->foreignUuid('employee_assignment_id')->nullable()->after('employee_id')->constrained('employee_assignments')->nullOnDelete(),
            'incident_date' => fn (Blueprint $t) => $t->date('incident_date')->nullable()->after('description'),
            'priority' => fn (Blueprint $t) => $t->string('priority', 20)->default('normal')->after('incident_date'),
            'confidentiality_level' => fn (Blueprint $t) => $t->string('confidentiality_level', 30)->default('normal_confidential')->after('priority')->index(),
            'current_stage_id' => fn (Blueprint $t) => $t->uuid('current_stage_id')->nullable()->after('status')->index(),
            'current_handler_type' => fn (Blueprint $t) => $t->string('current_handler_type', 30)->nullable()->after('current_stage_id'),
            'current_handler_id' => fn (Blueprint $t) => $t->uuid('current_handler_id')->nullable()->after('current_handler_type'),
            'accepted_at' => fn (Blueprint $t) => $t->timestamp('accepted_at')->nullable(),
            'resolved_at' => fn (Blueprint $t) => $t->timestamp('resolved_at')->nullable(),
            'intake_reason_code' => fn (Blueprint $t) => $t->string('intake_reason_code', 60)->nullable(),
            'intake_notes' => fn (Blueprint $t) => $t->text('intake_notes')->nullable(),
            'withdraw_requested_at' => fn (Blueprint $t) => $t->timestamp('withdraw_requested_at')->nullable(),
            'withdrawn_at' => fn (Blueprint $t) => $t->timestamp('withdrawn_at')->nullable(),
            'withdrawal_reason' => fn (Blueprint $t) => $t->text('withdrawal_reason')->nullable(),
            'withdrawal_reason_code' => fn (Blueprint $t) => $t->string('withdrawal_reason_code', 60)->nullable(),
            'closure_reason_code' => fn (Blueprint $t) => $t->string('closure_reason_code', 60)->nullable(),
            'closure_notes' => fn (Blueprint $t) => $t->text('closure_notes')->nullable(),
            'record_state' => fn (Blueprint $t) => $t->string('record_state', 20)->default('active')->index(),
            'archived_at' => fn (Blueprint $t) => $t->timestamp('archived_at')->nullable(),
            'legal_hold' => fn (Blueprint $t) => $t->boolean('legal_hold')->default(false),
            'legal_hold_reason' => fn (Blueprint $t) => $t->text('legal_hold_reason')->nullable(),
            'retention_until' => fn (Blueprint $t) => $t->date('retention_until')->nullable(),
            'appeal_deadline_at' => fn (Blueprint $t) => $t->timestamp('appeal_deadline_at')->nullable(),
            'respondent_type' => fn (Blueprint $t) => $t->string('respondent_type', 30)->nullable(),
            'respondent_employee_id' => fn (Blueprint $t) => $t->foreignUuid('respondent_employee_id')->nullable()->constrained('employees')->nullOnDelete(),
            'respondent_organization_unit_id' => fn (Blueprint $t) => $t->foreignUuid('respondent_organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete(),
            'respondent_description' => fn (Blueprint $t) => $t->text('respondent_description')->nullable(),
            'root_cause_category' => fn (Blueprint $t) => $t->string('root_cause_category', 60)->nullable(),
            'systemic_issue_flag' => fn (Blueprint $t) => $t->boolean('systemic_issue_flag')->default(false),
            'corrective_action_required' => fn (Blueprint $t) => $t->boolean('corrective_action_required')->default(false),
            'reopened_count' => fn (Blueprint $t) => $t->unsignedSmallInteger('reopened_count')->default(0),
        ]);
        $this->addIndex('grievances', 'grievances_current_handler_index', fn (Blueprint $t) => $t->index(['current_handler_type', 'current_handler_id'], 'grievances_current_handler_index'));
        $this->addIndex('grievances', 'grievances_submitted_at_index', fn (Blueprint $t) => $t->index('submitted_at'));
        $this->addIndex('grievances', 'grievances_category_id_index', fn (Blueprint $t) => $t->index('category_id'));
        $this->addIndex('grievances', 'grievances_status_index', fn (Blueprint $t) => $t->index('status'));

        $this->addColumns('grievance_categories', [
            'default_confidentiality' => fn (Blueprint $t) => $t->string('default_confidentiality', 30)->nullable(),
            'default_priority' => fn (Blueprint $t) => $t->string('default_priority', 20)->nullable(),
            'requires_executive_approval' => fn (Blueprint $t) => $t->boolean('requires_executive_approval')->default(false),
            'sort_order' => fn (Blueprint $t) => $t->integer('sort_order')->default(0),
        ]);

        $this->addColumns('grievance_committees', [
            'description_en' => fn (Blueprint $t) => $t->text('description_en')->nullable(),
            'description_am' => fn (Blueprint $t) => $t->text('description_am')->nullable(),
            'effective_from' => fn (Blueprint $t) => $t->date('effective_from')->nullable(),
            'effective_to' => fn (Blueprint $t) => $t->date('effective_to')->nullable(),
            'created_by' => fn (Blueprint $t) => $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(),
            'approved_by' => fn (Blueprint $t) => $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete(),
            'approved_at' => fn (Blueprint $t) => $t->timestamp('approved_at')->nullable(),
        ]);

        // A committee is re-constituted for a new term as a new record; the old
        // unique (org, unit, type) blocked that and, with a NULL unit, never
        // held on PostgreSQL anyway. Relaxed to a plain index. The plain index
        // is added first: on MySQL the foreign keys on these columns need an
        // index to remain once the unique one is dropped.
        $this->addIndex('grievance_committees', 'grievance_committees_org_type_status_index', fn (Blueprint $t) => $t->index(['organization_id', 'committee_type', 'status'], 'grievance_committees_org_type_status_index'));
        if (Schema::hasIndex('grievance_committees', 'unique_committee_per_org_unit_type')) {
            Schema::table('grievance_committees', fn (Blueprint $t) => $t->dropUnique('unique_committee_per_org_unit_type'));
        }

        $this->addColumns('grievance_committee_members', [
            'appointed_by' => fn (Blueprint $t) => $t->foreignId('appointed_by')->nullable()->constrained('users')->nullOnDelete(),
            'appointment_reference' => fn (Blueprint $t) => $t->string('appointment_reference')->nullable(),
            'end_reason' => fn (Blueprint $t) => $t->text('end_reason')->nullable(),
        ]);
        $this->addIndex('grievance_committee_members', 'grievance_committee_members_employee_status_index', fn (Blueprint $t) => $t->index(['employee_id', 'status'], 'grievance_committee_members_employee_status_index'));

        // ── Configuration ────────────────────────────────────────────────────
        $this->createTable('grievance_external_authorities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->boolean('is_administrative_tribunal')->default(false);
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $this->createTable('grievance_sla_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->string('purpose', 30)->default('resolution');
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('handler_type', 30)->nullable();
            $table->uuid('handler_id')->nullable();
            $table->foreignUuid('category_id')->nullable()->constrained('grievance_categories')->nullOnDelete();
            $table->unsignedSmallInteger('resolution_days');
            $table->string('day_type', 20)->default('working_days');
            $table->string('start_point', 20)->default('on_assignment');
            $table->json('warning_thresholds')->nullable();
            $table->boolean('auto_escalate')->default(true);
            $table->integer('priority')->default(100);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['purpose', 'is_active']);
            $table->index(['handler_type', 'handler_id']);
        });

        $this->createTable('grievance_routes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_handler_type', 30);
            $table->uuid('source_handler_id');
            $table->boolean('include_descendants')->default(false);
            $table->string('target_handler_type', 30);
            $table->uuid('target_handler_id');
            $table->string('movement_type', 30);
            $table->foreignUuid('category_id')->nullable()->constrained('grievance_categories')->nullOnDelete();
            $table->foreignUuid('sla_profile_id')->nullable()->constrained('grievance_sla_profiles')->nullOnDelete();
            $table->integer('priority')->default(100);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['source_handler_type', 'source_handler_id', 'movement_type'], 'grievance_routes_source_index');
            $table->index(['target_handler_type', 'target_handler_id'], 'grievance_routes_target_index');
        });

        $this->createTable('grievance_approval_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('handler_type', 30)->nullable();
            $table->uuid('handler_id')->nullable();
            $table->foreignUuid('category_id')->nullable()->constrained('grievance_categories')->nullOnDelete();
            $table->string('decision_type', 30)->nullable();
            $table->boolean('requires_approval')->default(true);
            $table->foreignUuid('approver_position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignUuid('approval_sla_profile_id')->nullable()->constrained('grievance_sla_profiles')->nullOnDelete();
            $table->integer('priority')->default(100);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'organization_id']);
        });

        $this->createTable('grievance_delegations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('delegator_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegate_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('authority', 40)->default('decision_approval');
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignUuid('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['delegate_user_id', 'status']);
        });

        $this->createTable('grievance_reason_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 30);
            $table->string('code', 60);
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['type', 'code']);
        });

        $this->createTable('organization_letterheads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
            $table->string('header_line_en')->nullable();
            $table->string('header_line_am')->nullable();
            $table->text('address_en')->nullable();
            $table->text('address_am')->nullable();
            $table->string('po_box', 60)->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('fax', 60)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->text('footer_en')->nullable();
            $table->text('footer_am')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->createTable('organization_seals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->string('disk', 30)->default('local');
            $table->string('path');
            $table->string('mime_type', 100);
            $table->string('sha256', 64);
            $table->string('status', 20)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        $this->createTable('grievance_letter_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('template_type', 40);
            $table->string('language', 12);
            $table->string('name');
            $table->string('subject_template');
            $table->text('body_template');
            $table->json('header_config')->nullable();
            $table->json('footer_config')->nullable();
            $table->json('signature_config')->nullable();
            $table->json('seal_config')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['template_type', 'language', 'is_active'], 'grievance_letter_templates_lookup_index');
        });

        // ── Case lifecycle ───────────────────────────────────────────────────
        $this->createTable('grievance_case_stages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->constrained('grievances')->cascadeOnDelete();
            $table->unsignedSmallInteger('stage_no');
            $table->string('handler_type', 30);
            $table->uuid('handler_id');
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->foreignUuid('committee_id')->nullable()->constrained('grievance_committees')->nullOnDelete();
            $table->foreignUuid('external_authority_id')->nullable()->constrained('grievance_external_authorities')->nullOnDelete();
            $table->foreignUuid('route_id')->nullable()->constrained('grievance_routes')->nullOnDelete();
            // One successor per stage: the database-level guard against a
            // double escalation/appeal of the same stage.
            $table->uuid('from_stage_id')->nullable()->unique();
            $table->string('movement_type', 30);
            $table->text('movement_reason')->nullable();
            $table->foreignId('moved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('pending');
            $table->timestamp('received_at')->nullable();
            $table->timestamp('review_started_at')->nullable();
            $table->foreignUuid('sla_profile_id')->nullable()->constrained('grievance_sla_profiles')->nullOnDelete();
            $table->unsignedSmallInteger('sla_days')->nullable();
            $table->string('sla_day_type', 20)->nullable();
            $table->string('sla_start_point', 20)->nullable();
            $table->timestamp('sla_started_at')->nullable();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('original_due_at')->nullable();
            $table->unsignedInteger('paused_days')->default(0);
            $table->boolean('auto_escalate')->default(false);
            $table->json('warnings_sent')->nullable();
            $table->uuid('decision_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamps();

            $table->unique(['grievance_id', 'stage_no']);
            $table->index(['handler_type', 'handler_id', 'is_current'], 'grievance_case_stages_handler_index');
            $table->index(['status', 'due_at']);
        });

        // At most one current stage per case.
        $this->oneCurrentStagePerCase();

        $this->createTable('grievance_stage_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('case_stage_id')->constrained('grievance_case_stages')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUuid('committee_member_id')->nullable()->constrained('grievance_committee_members')->nullOnDelete();
            $table->string('role', 20);
            $table->string('source', 20)->default('committee');
            $table->boolean('is_active')->default(true);
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->timestamp('recused_at')->nullable();
            $table->uuid('replaces_stage_member_id')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'is_active']);
            $table->unique(['case_stage_id', 'employee_id']);
        });

        $this->createTable('grievance_case_officers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->constrained('grievances')->cascadeOnDelete();
            $table->foreignUuid('case_stage_id')->constrained('grievance_case_stages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('role', 20)->default('officer');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['case_stage_id', 'user_id']);
            $table->index(['user_id', 'released_at']);
        });

        $this->createTable('grievance_sla_pauses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->constrained('grievances')->cascadeOnDelete();
            $table->foreignUuid('case_stage_id')->constrained('grievance_case_stages')->cascadeOnDelete();
            $table->string('pause_reason', 40);
            $table->string('status', 20)->default('requested');
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at_before')->nullable();
            $table->timestamp('due_at_after')->nullable();
            $table->unsignedInteger('paused_days')->nullable();
            $table->timestamps();

            $table->index(['case_stage_id', 'status']);
        });

        $this->createTable('grievance_case_recusals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->constrained('grievances')->cascadeOnDelete();
            $table->foreignUuid('case_stage_id')->constrained('grievance_case_stages')->cascadeOnDelete();
            $table->foreignUuid('committee_id')->nullable()->constrained('grievance_committees')->nullOnDelete();
            $table->foreignUuid('stage_member_id')->nullable()->constrained('grievance_stage_members')->nullOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->text('reason');
            $table->string('status', 20)->default('declared');
            $table->foreignId('declared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('declared_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->foreignUuid('replacement_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->uuid('replacement_stage_member_id')->nullable();
            $table->timestamps();

            $table->index(['case_stage_id', 'status']);
        });

        $this->createTable('grievance_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->constrained('grievances')->cascadeOnDelete();
            $table->foreignUuid('case_stage_id')->constrained('grievance_case_stages')->cascadeOnDelete();
            $table->string('decision_no', 80)->nullable()->unique();
            $table->unsignedSmallInteger('version_no');
            $table->string('decision_type', 30)->nullable();
            $table->text('findings')->nullable();
            $table->text('facts_considered')->nullable();
            $table->text('legal_basis')->nullable();
            $table->text('analysis')->nullable();
            $table->text('decision_text');
            $table->text('recommendations')->nullable();
            $table->string('status', 40)->default('draft');
            $table->boolean('requires_executive_approval')->default(false);
            $table->foreignUuid('approval_rule_id')->nullable()->constrained('grievance_approval_rules')->nullOnDelete();
            $table->foreignUuid('approver_position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->boolean('corrective_action_required')->default(false);
            $table->boolean('disciplinary_referral_recommended')->default(false);
            $table->boolean('quorum_met')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('prepared_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('submitted_for_review_at')->nullable();
            $table->timestamp('submitted_for_approval_at')->nullable();
            $table->timestamp('approval_due_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->uuid('supersedes_decision_id')->nullable();
            $table->uuid('legacy_response_id')->nullable()->unique();
            $table->timestamps();

            $table->unique(['case_stage_id', 'version_no']);
            $table->index(['grievance_id', 'status']);
            $table->index(['status', 'approval_due_at']);
        });

        $this->createTable('grievance_decision_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('decision_id')->constrained('grievance_decisions')->cascadeOnDelete();
            $table->string('action', 40);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('actor_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignUuid('actor_position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignUuid('delegation_id')->nullable()->constrained('grievance_delegations')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamp('acted_at');
            $table->timestamps();

            $table->index(['decision_id', 'acted_at']);
        });

        $this->createTable('grievance_decision_votes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('decision_id')->constrained('grievance_decisions')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('vote', 20);
            $table->text('opinion')->nullable();
            $table->timestamp('voted_at');
            $table->timestamps();

            $table->unique(['decision_id', 'employee_id']);
        });

        $this->createTable('grievance_hearings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->constrained('grievances')->cascadeOnDelete();
            $table->foreignUuid('case_stage_id')->constrained('grievance_case_stages')->cascadeOnDelete();
            $table->timestamp('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('location')->nullable();
            $table->string('mode', 20)->default('in_person');
            $table->text('meeting_link')->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->foreignUuid('chairperson_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('agenda')->nullable();
            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('held_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['case_stage_id', 'status']);
            $table->index('scheduled_at');
        });

        $this->createTable('grievance_hearing_participants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('hearing_id')->constrained('grievance_hearings')->cascadeOnDelete();
            $table->string('role', 30);
            $table->foreignUuid('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('affiliation')->nullable();
            $table->text('contact')->nullable();
            $table->string('attendance', 20)->default('invited');
            $table->timestamp('notice_sent_at')->nullable();
            $table->string('notice_channel', 30)->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
        });

        $this->createTable('grievance_minutes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->constrained('grievances')->cascadeOnDelete();
            $table->foreignUuid('case_stage_id')->constrained('grievance_case_stages')->cascadeOnDelete();
            $table->foreignUuid('hearing_id')->nullable()->constrained('grievance_hearings')->nullOnDelete();
            $table->date('meeting_date');
            $table->text('summary');
            $table->text('discussion')->nullable();
            $table->text('resolutions')->nullable();
            $table->json('attendees')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('version_no')->default(1);
            $table->uuid('supersedes_minutes_id')->nullable();
            $table->text('amendment_reason')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('prepared_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['case_stage_id', 'status']);
        });

        $this->createTable('grievance_information_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->constrained('grievances')->cascadeOnDelete();
            $table->foreignUuid('case_stage_id')->constrained('grievance_case_stages')->cascadeOnDelete();
            $table->string('requested_from_type', 30);
            $table->string('requested_from_id', 64)->nullable();
            $table->string('requested_from_name')->nullable();
            $table->text('request_text');
            $table->timestamp('due_at')->nullable();
            $table->string('status', 20)->default('open');
            $table->boolean('pauses_sla')->default(false);
            $table->foreignUuid('sla_pause_id')->nullable()->constrained('grievance_sla_pauses')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['grievance_id', 'status']);
            $table->index(['requested_from_type', 'requested_from_id', 'status'], 'grievance_info_requests_target_index');
        });

        $this->createTable('grievance_information_responses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('information_request_id')->constrained('grievance_information_requests')->cascadeOnDelete();
            $table->foreignUuid('grievance_id')->constrained('grievances')->cascadeOnDelete();
            $table->text('response_text');
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('responded_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('responded_at');
            $table->timestamps();
        });

        $this->createTable('grievance_appeals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->constrained('grievances')->cascadeOnDelete();
            // One appeal per decision: the database-level duplicate guard.
            $table->foreignUuid('appealed_decision_id')->unique()->constrained('grievance_decisions')->cascadeOnDelete();
            $table->foreignUuid('from_stage_id')->constrained('grievance_case_stages')->cascadeOnDelete();
            $table->uuid('to_stage_id')->nullable();
            $table->text('reason');
            $table->string('status', 20)->default('submitted');
            $table->foreignId('filed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('filed_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('filed_at');
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('routed_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
        });

        $this->createTable('grievance_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->constrained('grievances')->cascadeOnDelete();
            $table->foreignUuid('case_stage_id')->nullable()->constrained('grievance_case_stages')->nullOnDelete();
            $table->foreignUuid('information_request_id')->nullable()->constrained('grievance_information_requests')->nullOnDelete();
            $table->foreignUuid('information_response_id')->nullable()->constrained('grievance_information_responses')->nullOnDelete();
            $table->foreignUuid('appeal_id')->nullable()->constrained('grievance_appeals')->nullOnDelete();
            $table->string('evidence_type', 20);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('disk', 30)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->string('classification', 30)->default('normal_confidential');
            $table->string('status', 20)->default('submitted');
            $table->string('scan_status', 20)->default('not_scanned');
            $table->unsignedSmallInteger('version_no')->default(1);
            $table->uuid('supersedes_evidence_id')->nullable();
            $table->boolean('submitted_by_complainant')->default(false);
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('submitted_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('submitted_at');
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['grievance_id', 'status']);
        });

        $this->createTable('grievance_evidence_custody', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('evidence_id')->constrained('grievance_evidence')->cascadeOnDelete();
            $table->string('action', 20);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['evidence_id', 'occurred_at']);
        });

        $this->createTable('grievance_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->constrained('grievances')->cascadeOnDelete();
            $table->foreignUuid('case_stage_id')->nullable()->constrained('grievance_case_stages')->nullOnDelete();
            $table->text('body');
            $table->string('visibility', 20)->default('internal');
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['grievance_id', 'created_at']);
        });

        $this->createTable('grievance_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->constrained('grievances')->cascadeOnDelete();
            $table->foreignUuid('case_stage_id')->nullable()->constrained('grievance_case_stages')->nullOnDelete();
            $table->string('task_type', 30);
            $table->string('title');
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['assigned_to_user_id', 'status']);
            $table->index(['grievance_id', 'status']);
        });

        $this->createTable('grievance_case_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->constrained('grievances')->cascadeOnDelete();
            $table->uuid('case_stage_id')->nullable();
            $table->string('event', 60);
            $table->string('visibility', 20)->default('internal');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('data')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['grievance_id', 'occurred_at']);
        });

        $this->createTable('grievance_amendments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->constrained('grievances')->cascadeOnDelete();
            $table->json('changes');
            $table->text('reason')->nullable();
            $table->foreignId('amended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('amended_at');
            $table->timestamps();
        });

        // ── Correspondence ───────────────────────────────────────────────────
        $this->createTable('grievance_letters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->constrained('grievances')->cascadeOnDelete();
            $table->foreignUuid('case_stage_id')->nullable()->constrained('grievance_case_stages')->nullOnDelete();
            $table->foreignUuid('decision_id')->nullable()->constrained('grievance_decisions')->nullOnDelete();
            $table->foreignUuid('hearing_id')->nullable()->constrained('grievance_hearings')->nullOnDelete();
            $table->foreignUuid('information_request_id')->nullable()->constrained('grievance_information_requests')->nullOnDelete();
            $table->foreignUuid('appeal_id')->nullable()->constrained('grievance_appeals')->nullOnDelete();
            $table->foreignUuid('template_id')->nullable()->constrained('grievance_letter_templates')->nullOnDelete();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('letter_type', 40);
            $table->string('language', 12);
            $table->string('reference_number', 80)->nullable()->unique();
            $table->string('subject');
            $table->text('body');
            $table->date('letter_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignUuid('signatory_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignUuid('signatory_position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignId('signatory_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signature_method', 40)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->foreignUuid('seal_id')->nullable()->constrained('organization_seals')->nullOnDelete();
            $table->foreignId('seal_applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('seal_applied_at')->nullable();
            $table->string('pdf_disk', 30)->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('pdf_sha256', 64)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->boolean('visible_to_complainant')->default(false);
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->uuid('supersedes_letter_id')->nullable();
            $table->uuid('legacy_decision_letter_id')->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['grievance_id', 'letter_type']);
            $table->index(['status', 'issued_at']);
        });

        $this->createTable('grievance_letter_recipients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('letter_id')->constrained('grievance_letters')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('recipient_type', 30);
            $table->string('recipient_id', 64)->nullable();
            $table->string('name');
            $table->string('position_title')->nullable();
            $table->string('organization_name')->nullable();
            $table->text('address')->nullable();
            $table->string('email')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $this->createTable('grievance_letter_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('letter_id')->constrained('grievance_letters')->cascadeOnDelete();
            $table->string('attachment_type', 30);
            $table->string('title');
            $table->foreignUuid('evidence_id')->nullable()->constrained('grievance_evidence')->nullOnDelete();
            $table->string('reference_id', 64)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $this->createTable('grievance_letter_dispatches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('letter_id')->constrained('grievance_letters')->cascadeOnDelete();
            $table->foreignUuid('recipient_id')->nullable()->constrained('grievance_letter_recipients')->nullOnDelete();
            $table->string('channel', 30);
            $table->string('status', 20)->default('queued');
            $table->string('destination')->nullable();
            $table->string('provider_reference')->nullable();
            $table->timestamp('queued_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['letter_id', 'channel']);
        });

        // ── Outcomes ─────────────────────────────────────────────────────────
        $this->createTable('grievance_corrective_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->constrained('grievances')->cascadeOnDelete();
            $table->foreignUuid('decision_id')->nullable()->constrained('grievance_decisions')->nullOnDelete();
            $table->text('description');
            $table->foreignUuid('responsible_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            // Named: the generated name exceeds MySQL's 64-character limit.
            $table->foreignUuid('responsible_organization_unit_id')->nullable()->constrained('organization_units', indexName: 'gca_responsible_unit_foreign')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('open');
            $table->text('completion_notes')->nullable();
            $table->foreignUuid('completion_evidence_id')->nullable()->constrained('grievance_evidence')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'due_date']);
        });

        $this->createTable('grievance_disciplinary_referrals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->constrained('grievances')->cascadeOnDelete();
            $table->foreignUuid('decision_id')->nullable()->constrained('grievance_decisions')->nullOnDelete();
            // Named: the generated names exceed MySQL's 64-character limit.
            $table->foreignUuid('referred_to_organization_id')->nullable()->constrained('organizations', indexName: 'gdr_referred_org_foreign')->nullOnDelete();
            $table->foreignUuid('referred_to_organization_unit_id')->nullable()->constrained('organization_units', indexName: 'gdr_referred_unit_foreign')->nullOnDelete();
            $table->text('reason');
            $table->string('status', 20)->default('referred');
            // A disciplinary module does not exist yet; its case id/reference
            // is recorded here when the receiving body opens one.
            $table->string('disciplinary_case_reference')->nullable();
            $table->uuid('disciplinary_case_id')->nullable();
            $table->foreignId('referred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('referred_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'grievance_disciplinary_referrals', 'grievance_corrective_actions',
            'grievance_letter_dispatches', 'grievance_letter_attachments', 'grievance_letter_recipients', 'grievance_letters',
            'grievance_amendments', 'grievance_case_events', 'grievance_tasks', 'grievance_notes',
            'grievance_evidence_custody', 'grievance_evidence', 'grievance_appeals',
            'grievance_information_responses', 'grievance_information_requests',
            'grievance_minutes', 'grievance_hearing_participants', 'grievance_hearings',
            'grievance_decision_votes', 'grievance_decision_approvals', 'grievance_decisions',
            'grievance_case_recusals', 'grievance_sla_pauses', 'grievance_case_officers', 'grievance_stage_members',
            'grievance_case_stages', 'grievance_letter_templates', 'organization_seals', 'organization_letterheads',
            'grievance_reason_codes', 'grievance_delegations', 'grievance_approval_rules', 'grievance_routes',
            'grievance_sla_profiles', 'grievance_external_authorities',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('grievance_committee_members', function (Blueprint $table): void {
            $table->dropIndex('grievance_committee_members_employee_status_index');
            $table->dropConstrainedForeignId('appointed_by');
            $table->dropColumn(['appointment_reference', 'end_reason']);
        });

        Schema::table('grievance_committees', function (Blueprint $table): void {
            $table->dropIndex('grievance_committees_org_type_status_index');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['description_en', 'description_am', 'effective_from', 'effective_to', 'approved_at']);
        });

        Schema::table('grievance_categories', function (Blueprint $table): void {
            $table->dropColumn(['default_confidentiality', 'default_priority', 'requires_executive_approval', 'sort_order']);
        });

        Schema::table('grievances', function (Blueprint $table): void {
            $table->dropIndex('grievances_current_handler_index');
            $table->dropIndex(['submitted_at']);
            $table->dropIndex(['category_id']);
            $table->dropIndex(['status']);
            $table->dropIndex(['confidentiality_level']);
            $table->dropIndex(['current_stage_id']);
            $table->dropIndex(['record_state']);
            $table->dropConstrainedForeignId('employee_assignment_id');
            $table->dropConstrainedForeignId('respondent_employee_id');
            $table->dropConstrainedForeignId('respondent_organization_unit_id');
            $table->dropColumn([
                'incident_date', 'priority', 'confidentiality_level', 'current_stage_id', 'current_handler_type',
                'current_handler_id', 'accepted_at', 'resolved_at', 'intake_reason_code', 'intake_notes',
                'withdraw_requested_at', 'withdrawn_at', 'withdrawal_reason', 'withdrawal_reason_code',
                'closure_reason_code', 'closure_notes', 'record_state', 'archived_at', 'legal_hold',
                'legal_hold_reason', 'retention_until', 'appeal_deadline_at', 'respondent_type',
                'respondent_description', 'root_cause_category', 'systemic_issue_flag',
                'corrective_action_required', 'reopened_count',
            ]);
        });
        // The relaxed committee unique is not restored: re-adding it could fail
        // on committees legitimately re-constituted while this was live.
    }

    /** @param array<string, \Closure(Blueprint): mixed> $columns column => definition */
    private function addColumns(string $table, array $columns): void
    {
        foreach ($columns as $column => $define) {
            if (! Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $blueprint) => $define($blueprint));
            }
        }
    }

    /** @param \Closure(Blueprint): mixed $define */
    private function addIndex(string $table, string $index, \Closure $define): void
    {
        if (! Schema::hasIndex($table, $index)) {
            Schema::table($table, fn (Blueprint $blueprint) => $define($blueprint));
        }
    }

    /** @param \Closure(Blueprint): mixed $define */
    private function createTable(string $table, \Closure $define): void
    {
        if (! Schema::hasTable($table)) {
            Schema::create($table, $define);
        }
    }

    /**
     * At most one current stage per case. PostgreSQL and SQLite take a
     * partial unique index. MySQL has none, so a generated column holds the
     * case id only on the current stage and is made unique; the other stages
     * hold NULL, which a unique index allows any number of. VIRTUAL, not
     * STORED: MySQL forbids a stored generated column whose base column has
     * an ON DELETE CASCADE foreign key, as grievance_id does.
     */
    private function oneCurrentStagePerCase(): void
    {
        if (Schema::hasIndex('grievance_case_stages', 'grievance_case_stages_one_current')) {
            return;
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            if (! Schema::hasColumn('grievance_case_stages', 'current_grievance_id')) {
                DB::statement('ALTER TABLE grievance_case_stages ADD COLUMN current_grievance_id CHAR(36) GENERATED ALWAYS AS (CASE WHEN is_current = 1 THEN grievance_id END) VIRTUAL');
            }
            DB::statement('CREATE UNIQUE INDEX grievance_case_stages_one_current ON grievance_case_stages (current_grievance_id)');

            return;
        }

        DB::statement('CREATE UNIQUE INDEX grievance_case_stages_one_current ON grievance_case_stages (grievance_id) WHERE is_current = '.(DB::getDriverName() === 'pgsql' ? 'true' : '1'));
    }
};
