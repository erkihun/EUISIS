<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Institutional Reporting & Compliance: every table the module owns
 * (Modules/Reporting/README.md, "Data ownership").
 *
 * - All tables are prefixed reporting_ and written only by the module.
 * - Organizations (uuid) and users (bigint) of the Core are stored as plain
 *   references: no foreign key leaves the module, so nothing here can cascade
 *   into or out of another owner's tables. Names and hierarchy are kept in
 *   explicit snapshot columns.
 * - Foreign keys inside the module restrict deletes; history is never removed
 *   by a cascade.
 * - Mandatory moments are dateTime (UTC), index names are short and explicit
 *   (MySQL's 64-character limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporting_report_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique('rpt_types_code_uq');
            $table->string('name_en', 200);
            $table->string('name_am', 200);
            $table->text('instructions_en')->nullable();
            $table->text('instructions_am')->nullable();
            $table->string('frequency', 20);
            $table->uuid('receiving_organization_id')->nullable();
            $table->string('submission_mode', 20)->default('either');
            $table->string('confidentiality', 20)->default('internal');
            $table->boolean('allow_nil_report')->default(false);
            $table->boolean('allow_self_approval')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'frequency'], 'rpt_types_active_ix');
        });

        // Immutable once published: a cycle pins one version, with its sections and review steps.
        Schema::create('reporting_template_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_type_id')->constrained('reporting_report_types', 'id', 'rpt_tplv_type_fk')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->json('workflow');
            $table->json('required_attachments')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['report_type_id', 'version'], 'rpt_tplv_type_version_uq');
        });

        Schema::create('reporting_template_sections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('template_version_id')->constrained('reporting_template_versions', 'id', 'rpt_tpls_version_fk')->cascadeOnDelete();
            $table->string('key', 60);
            $table->unsignedInteger('position');
            $table->string('title_en', 200);
            $table->string('title_am', 200);
            $table->text('guidance_en')->nullable();
            $table->text('guidance_am')->nullable();
            $table->boolean('is_required')->default(true);
            $table->unique(['template_version_id', 'key'], 'rpt_tpls_version_key_uq');
        });

        Schema::create('reporting_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_type_id')->constrained('reporting_report_types', 'id', 'rpt_sched_type_fk')->restrictOnDelete();
            $table->string('name_en', 200);
            $table->string('name_am', 200);
            $table->string('frequency', 20);
            $table->string('calendar_basis', 20)->default('ethiopian');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(11);
            $table->integer('opens_offset_days')->default(-7);
            $table->integer('due_offset_days')->default(5);
            $table->string('due_time', 5)->default('17:30');
            $table->string('timezone', 64)->default('Africa/Addis_Ababa');
            $table->string('holiday_adjustment', 30)->default('next_working_day');
            $table->unsignedInteger('review_sla_working_days')->default(5);
            $table->unsignedInteger('revision_days')->default(5);
            $table->string('late_policy', 30)->default('accept');
            $table->uuid('receiving_organization_id')->nullable();
            $table->json('reviewer_user_ids')->nullable();
            $table->json('reminder_rules')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'starts_on'], 'rpt_sched_active_ix');
        });

        // Who must report under a schedule: an institution, or an explicitly assigned person.
        Schema::create('reporting_schedule_targets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('schedule_id')->constrained('reporting_schedules', 'id', 'rpt_target_sched_fk')->cascadeOnDelete();
            $table->string('subject_key', 60);
            $table->uuid('organization_id');
            $table->unsignedBigInteger('person_user_id')->nullable();
            $table->unsignedBigInteger('preparer_user_id')->nullable();
            $table->unsignedBigInteger('accountable_user_id')->nullable();
            $table->timestamps();
            $table->unique(['schedule_id', 'subject_key'], 'rpt_target_subject_uq');
        });

        Schema::create('reporting_cycles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_type_id')->constrained('reporting_report_types', 'id', 'rpt_cycle_type_fk')->restrictOnDelete();
            $table->foreignUuid('schedule_id')->nullable()->constrained('reporting_schedules', 'id', 'rpt_cycle_sched_fk')->restrictOnDelete();
            $table->foreignUuid('template_version_id')->constrained('reporting_template_versions', 'id', 'rpt_cycle_tplv_fk')->restrictOnDelete();
            $table->string('period_key', 60);
            // schedule id, or "adhoc:<uuid>": the unique key that makes publication idempotent.
            $table->string('series_key', 60);
            $table->string('period_label_en', 200);
            $table->string('period_label_am', 200);
            $table->date('period_start');
            $table->date('period_end');
            $table->dateTime('opens_at');
            $table->dateTime('due_at');
            $table->string('timezone', 64)->default('Africa/Addis_Ababa');
            $table->string('calendar_basis', 20)->default('ethiopian');
            $table->unsignedInteger('review_sla_working_days')->default(5);
            $table->unsignedInteger('revision_days')->default(5);
            $table->string('late_policy', 30)->default('accept');
            $table->uuid('receiving_organization_id')->nullable();
            $table->string('receiving_name_en', 255)->nullable();
            $table->string('receiving_name_am', 255)->nullable();
            $table->json('reviewer_user_ids')->nullable();
            $table->json('reminder_rules')->nullable();
            $table->json('workflow');
            $table->string('hierarchy_version_ref', 60)->nullable();
            $table->string('status', 20)->default('published');
            $table->dateTime('published_at');
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamps();
            $table->unique(['series_key', 'period_key'], 'rpt_cycle_series_period_uq');
            $table->index(['status', 'due_at'], 'rpt_cycle_status_due_ix');
            $table->index(['report_type_id', 'period_end'], 'rpt_cycle_type_period_ix');
        });

        Schema::create('reporting_obligations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cycle_id')->constrained('reporting_cycles', 'id', 'rpt_obl_cycle_fk')->restrictOnDelete();
            $table->uuid('report_type_id');
            $table->uuid('template_version_id');
            // "org:<uuid>" or "user:<id>": one obligation per subject and cycle, whoever collaborates.
            $table->string('subject_key', 60);
            $table->uuid('organization_id');
            $table->unsignedBigInteger('person_user_id')->nullable();
            $table->string('organization_code', 60)->nullable();
            $table->string('organization_name_en', 255)->nullable();
            $table->string('organization_name_am', 255)->nullable();
            $table->json('hierarchy_snapshot')->nullable();
            $table->unsignedBigInteger('preparer_user_id')->nullable();
            $table->string('preparer_name', 255)->nullable();
            $table->unsignedBigInteger('accountable_user_id')->nullable();
            $table->string('accountable_name', 255)->nullable();
            $table->uuid('receiving_organization_id')->nullable();
            $table->dateTime('opens_at');
            $table->dateTime('original_due_at');
            $table->dateTime('effective_due_at');
            $table->string('applicability', 20)->default('required');
            $table->string('workflow_status', 20)->default('not_started');
            $table->unsignedInteger('current_version')->default(1);
            $table->uuid('first_submission_id')->nullable();
            $table->dateTime('first_submitted_at')->nullable();
            $table->uuid('latest_submission_id')->nullable();
            $table->dateTime('latest_submitted_at')->nullable();
            $table->dateTime('revision_due_at')->nullable();
            $table->dateTime('review_due_at')->nullable();
            $table->unsignedBigInteger('current_reviewer_user_id')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->dateTime('deadline_missed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['cycle_id', 'subject_key'], 'rpt_obl_cycle_subject_uq');
            $table->index(['organization_id', 'effective_due_at'], 'rpt_obl_org_due_ix');
            $table->index(['applicability', 'effective_due_at'], 'rpt_obl_appl_due_ix');
            $table->index(['workflow_status', 'review_due_at'], 'rpt_obl_wf_review_ix');
            $table->index(['receiving_organization_id', 'workflow_status'], 'rpt_obl_recv_wf_ix');
            $table->index(['current_reviewer_user_id', 'workflow_status'], 'rpt_obl_reviewer_ix');
            $table->index(['preparer_user_id', 'workflow_status'], 'rpt_obl_preparer_ix');
            $table->index(['report_type_id', 'effective_due_at'], 'rpt_obl_type_due_ix');
        });

        // The working copy of one version. Never a submission; replaced freely until submitted.
        Schema::create('reporting_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('obligation_id')->constrained('reporting_obligations', 'id', 'rpt_draft_obl_fk')->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('mode', 20)->default('structured');
            $table->json('content')->nullable();
            $table->text('summary')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_nil')->default(false);
            $table->text('nil_reason')->nullable();
            $table->boolean('completeness_declared')->default(false);
            $table->unsignedInteger('lock_version')->default(0);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['obligation_id', 'version_no'], 'rpt_draft_obl_version_uq');
        });

        // Immutable: the snapshot taken at the moment of submission.
        Schema::create('reporting_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('obligation_id')->constrained('reporting_obligations', 'id', 'rpt_sub_obl_fk')->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('reference_no', 40)->unique('rpt_sub_reference_uq');
            $table->string('mode', 20);
            $table->json('content')->nullable();
            $table->text('summary')->nullable();
            $table->json('metadata')->nullable();
            $table->json('sections_snapshot');
            $table->boolean('is_nil')->default(false);
            $table->text('nil_reason')->nullable();
            $table->boolean('completeness_declared')->default(false);
            $table->boolean('is_late')->default(false);
            $table->text('late_reason')->nullable();
            $table->string('content_hash', 64);
            $table->unsignedBigInteger('submitted_by');
            $table->string('submitted_by_name', 255);
            $table->dateTime('submitted_at');
            $table->string('status', 20)->default('submitted');
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['obligation_id', 'version_no'], 'rpt_sub_obl_version_uq');
            $table->index(['status', 'submitted_at'], 'rpt_sub_status_ix');
        });

        Schema::create('reporting_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('obligation_id')->constrained('reporting_obligations', 'id', 'rpt_att_obl_fk')->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->uuid('submission_id')->nullable()->index('rpt_att_submission_ix');
            // report_document (the uploaded Word report), annex, evidence.
            $table->string('kind', 30);
            $table->string('requirement_key', 60)->nullable();
            $table->string('original_name', 255);
            $table->string('disk', 40);
            $table->string('path', 500);
            $table->string('mime', 150);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->string('scan_status', 20)->default('not_scanned');
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamps();
            $table->index(['obligation_id', 'version_no'], 'rpt_att_obl_version_ix');
        });

        // Generated files (Word, optional PDF). The row is the durable request: a lost queue message is retried from it.
        Schema::create('reporting_artifacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('owner_type', 20);
            $table->uuid('owner_id');
            $table->string('format', 10);
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('disk', 40)->nullable();
            $table->string('path', 500)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            // draft, submitted or approved: the label printed on the document.
            $table->string('label', 20)->default('submitted');
            $table->text('error')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id', 'format', 'label'], 'rpt_artifact_owner_uq');
            $table->index(['status', 'updated_at'], 'rpt_artifact_status_ix');
        });

        Schema::create('reporting_review_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('submission_id')->constrained('reporting_submissions', 'id', 'rpt_step_sub_fk')->restrictOnDelete();
            $table->uuid('obligation_id')->index('rpt_step_obl_ix');
            $table->unsignedInteger('step_no');
            $table->string('name_en', 200);
            $table->string('name_am', 200);
            $table->string('required_permission', 60);
            $table->unsignedBigInteger('reviewer_user_id')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->string('status', 20)->default('waiting');
            $table->string('decision', 20)->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->string('decided_by_name', 255)->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['submission_id', 'step_no'], 'rpt_step_sub_no_uq');
            $table->index(['status', 'due_at'], 'rpt_step_status_due_ix');
            $table->index(['reviewer_user_id', 'status'], 'rpt_step_reviewer_ix');
        });

        // Delegation and reassignment of a review step, kept as history.
        Schema::create('reporting_review_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('review_step_id')->constrained('reporting_review_steps', 'id', 'rpt_assign_step_fk')->restrictOnDelete();
            $table->string('kind', 20);
            $table->unsignedBigInteger('from_user_id')->nullable();
            $table->unsignedBigInteger('to_user_id');
            $table->text('reason');
            $table->unsignedBigInteger('assigned_by');
            $table->dateTime('created_at');
        });

        Schema::create('reporting_comments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('obligation_id')->constrained('reporting_obligations', 'id', 'rpt_comment_obl_fk')->restrictOnDelete();
            $table->uuid('submission_id')->nullable();
            $table->string('section_key', 60)->nullable();
            // submitter: feedback the reporting side reads; internal: reviewers' notes only.
            $table->string('visibility', 20)->default('submitter');
            $table->text('body');
            $table->unsignedBigInteger('author_user_id');
            $table->string('author_name', 255);
            $table->dateTime('created_at');
            $table->index(['obligation_id', 'created_at'], 'rpt_comment_obl_ix');
        });

        Schema::create('reporting_extensions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('obligation_id')->constrained('reporting_obligations', 'id', 'rpt_ext_obl_fk')->restrictOnDelete();
            $table->dateTime('requested_due_at');
            $table->text('reason');
            $table->unsignedBigInteger('requested_by');
            $table->string('requested_by_name', 255);
            $table->dateTime('requested_at');
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->string('decided_by_name', 255)->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->dateTime('previous_due_at')->nullable();
            $table->dateTime('approved_due_at')->nullable();
            // Granted after the deadline it replaces had already passed.
            $table->boolean('is_retrospective')->default(false);
            $table->timestamps();
            $table->index(['obligation_id', 'status'], 'rpt_ext_obl_status_ix');
            $table->index(['status', 'requested_at'], 'rpt_ext_status_ix');
        });

        // Exemptions, cancellations and reinstatements: effective-dated, never rewritten.
        Schema::create('reporting_applicability_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('obligation_id')->constrained('reporting_obligations', 'id', 'rpt_appl_obl_fk')->restrictOnDelete();
            $table->string('applicability', 20);
            $table->text('reason');
            $table->unsignedBigInteger('decided_by');
            $table->string('decided_by_name', 255);
            $table->dateTime('effective_at');
            $table->dateTime('created_at');
            $table->index(['obligation_id', 'effective_at'], 'rpt_appl_obl_ix');
        });

        Schema::create('reporting_follow_ups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('obligation_id')->constrained('reporting_obligations', 'id', 'rpt_fu_obl_fk')->restrictOnDelete();
            $table->uuid('submission_id')->nullable();
            $table->uuid('organization_id');
            $table->string('title', 255);
            $table->text('description');
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->string('owner_name', 255)->nullable();
            $table->string('priority', 20)->default('normal');
            $table->dateTime('due_at');
            $table->string('status', 20)->default('open');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->text('closure_note')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->string('created_by_name', 255);
            $table->timestamps();
            $table->index(['organization_id', 'status', 'due_at'], 'rpt_fu_org_status_ix');
            $table->index(['owner_user_id', 'status'], 'rpt_fu_owner_ix');
        });

        Schema::create('reporting_follow_up_updates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('follow_up_id')->constrained('reporting_follow_ups', 'id', 'rpt_fuu_fu_fk')->restrictOnDelete();
            $table->unsignedTinyInteger('progress');
            $table->string('status', 20);
            $table->text('note');
            $table->string('evidence_name', 255)->nullable();
            $table->string('evidence_disk', 40)->nullable();
            $table->string('evidence_path', 500)->nullable();
            $table->unsignedBigInteger('author_user_id');
            $table->string('author_name', 255);
            $table->dateTime('created_at');
        });

        // One row per notice sent; the unique key is what stops a duplicate.
        Schema::create('reporting_notification_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('delivery_key', 190)->unique('rpt_delivery_key_uq');
            $table->string('track', 20);
            $table->string('stage', 40);
            $table->uuid('obligation_id')->nullable()->index('rpt_delivery_obl_ix');
            $table->unsignedBigInteger('recipient_user_id');
            $table->json('channels');
            $table->string('status', 20)->default('requested');
            $table->text('error')->nullable();
            $table->dateTime('created_at');
        });

        Schema::create('reporting_consolidations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cycle_id')->constrained('reporting_cycles', 'id', 'rpt_cons_cycle_fk')->restrictOnDelete();
            $table->uuid('parent_organization_id');
            $table->string('parent_name_en', 255)->nullable();
            $table->string('parent_name_am', 255)->nullable();
            $table->string('title', 255);
            $table->longText('executive_summary')->nullable();
            $table->string('status', 20)->default('draft');
            $table->json('missing')->nullable();
            $table->json('indicators')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->string('created_by_name', 255);
            $table->unsignedBigInteger('compiled_by')->nullable();
            $table->dateTime('compiled_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
            $table->index(['cycle_id', 'parent_organization_id'], 'rpt_cons_cycle_parent_ix');
        });

        Schema::create('reporting_consolidation_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('consolidation_id')->constrained('reporting_consolidations', 'id', 'rpt_cons_src_cons_fk')->cascadeOnDelete();
            $table->uuid('obligation_id');
            $table->uuid('submission_id');
            $table->unsignedInteger('version_no');
            $table->string('reference_no', 40);
            $table->uuid('organization_id');
            // One institution enters a consolidation once: no double counting of a version or a parent and its child report.
            $table->unique(['consolidation_id', 'obligation_id'], 'rpt_cons_src_obl_uq');
        });

        // Indicator values captured from another owner's public contract, frozen with the submission that used them.
        Schema::create('reporting_metric_captures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('owner_type', 20);
            $table->uuid('owner_id');
            $table->string('source', 80);
            $table->string('metric_key', 120);
            $table->string('definition', 500);
            $table->json('scope');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('value', 20, 4)->nullable();
            $table->string('unit', 40)->nullable();
            $table->dateTime('captured_at');
            $table->boolean('is_frozen')->default(false);
            $table->index(['owner_type', 'owner_id'], 'rpt_metric_owner_ix');
        });

        // Transactional outbox: written in the same transaction as the fact, relayed afterwards.
        Schema::create('reporting_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id')->unique('rpt_outbox_event_uq');
            $table->string('event_type', 80);
            $table->unsignedInteger('event_version');
            $table->json('payload');
            $table->dateTime('occurred_at');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('available_at');
            $table->dateTime('dispatched_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at'], 'rpt_outbox_status_ix');
        });

        // Consumer-side idempotency: an event is handled by a consumer once.
        Schema::create('reporting_inbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('consumer', 80);
            $table->uuid('event_id');
            $table->dateTime('processed_at');
            $table->unique(['consumer', 'event_id'], 'rpt_inbox_consumer_event_uq');
        });

        // The module's own append-only history of an obligation (reassignments, deadline changes, decisions).
        Schema::create('reporting_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('obligation_id')->nullable();
            $table->string('event', 60);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_name', 255)->nullable();
            $table->json('data')->nullable();
            $table->dateTime('created_at');
            $table->index(['obligation_id', 'created_at'], 'rpt_event_obl_ix');
        });

        Schema::create('reporting_settings', function (Blueprint $table): void {
            $table->string('key', 80)->primary();
            $table->json('value');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('reporting_scheduler_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('task', 40);
            $table->string('status', 20);
            $table->json('stats')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->index(['task', 'started_at'], 'rpt_run_task_ix');
        });
    }

    public function down(): void
    {
        foreach ([
            'reporting_scheduler_runs', 'reporting_settings', 'reporting_events', 'reporting_inbox', 'reporting_outbox',
            'reporting_metric_captures', 'reporting_consolidation_sources', 'reporting_consolidations',
            'reporting_notification_deliveries', 'reporting_follow_up_updates', 'reporting_follow_ups',
            'reporting_applicability_decisions', 'reporting_extensions', 'reporting_comments',
            'reporting_review_assignments', 'reporting_review_steps', 'reporting_artifacts', 'reporting_attachments',
            'reporting_submissions', 'reporting_drafts', 'reporting_obligations', 'reporting_cycles',
            'reporting_schedule_targets', 'reporting_schedules', 'reporting_template_sections',
            'reporting_template_versions', 'reporting_report_types',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
