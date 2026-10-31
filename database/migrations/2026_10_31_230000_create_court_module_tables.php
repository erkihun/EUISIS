<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The court module's tables (ADR 0020), generated from the court-ms schema by
 * the port script: every court-ms table under a `court_` name, `users` as
 * `court_staff` (one row per platform account that works in the court, id =
 * users.id). Framework tables (jobs, cache, sessions, notifications, tokens,
 * migrations, pulse) are the platform's own and are not repeated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::create('court_staff', function (Blueprint $table) {
            $table->foreignId('id')->primary()->constrained('users')->cascadeOnDelete();
            $table->string('name', 255)->default('');
            $table->string('email', 255)->nullable();
            $table->string('phone', 255)->nullable();
            $table->text('address')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->char('euisis_employee_uuid', 36)->nullable();
            $table->timestamp('euisis_linked_at')->nullable();
            $table->timestamp('euisis_suspended_at')->nullable();
            $table->string('euisis_suspension_reason', 40)->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('national_id', 16)->nullable();
            $table->string('position', 255)->nullable();
            $table->enum('user_type', ['staff', 'judge', 'lawyer', 'client'])->default('staff');
            $table->timestamp('email_verified_at')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->boolean('must_change_password')->default(false);
            $table->string('avatar_path', 255)->nullable();
            $table->string('signature_path', 255)->nullable();
            $table->string('stamp_path', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->boolean('active_status')->default(false);
            $table->string('avatar', 255)->nullable();
            $table->boolean('dark_mode')->default(false);
            $table->string('messenger_color', 255)->nullable();
            $table->index(['status'], 'court_staff_status_index');
            $table->unique(['email'], 'court_staff_email_unique');
            $table->unique(['euisis_employee_uuid'], 'court_staff_euisis_employee_uuid_unique');
            $table->index(['is_admin'], 'court_staff_is_admin_index');
        });

        Schema::create('court_about_pages', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200)->default('');
            $table->string('slug', 200)->nullable();
            $table->longText('body')->nullable();
            $table->boolean('is_published')->default(false);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['created_by_user_id'], 'court_about_pages_created_by_user_id_index');
            $table->index(['is_published'], 'court_about_pages_is_published_index');
            $table->unique(['slug'], 'court_about_pages_slug_unique');
            $table->index(['updated_by_user_id'], 'court_about_pages_updated_by_user_id_index');
            $table->foreign(['created_by_user_id'], 'court_about_pages_created_by_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
            $table->foreign(['updated_by_user_id'], 'court_about_pages_updated_by_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
        });

        Schema::create('court_admin_notification_reads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->enum('type', ['message', 'case', 'hearing', 'respondent_view', 'staff_account'])->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['user_id'], 'court_admin_notification_reads_user_id_index');
            $table->unique(['user_id', 'type', 'source_id'], 'court_admin_notification_reads_user_id_type_source_id_unique');
            $table->index(['user_id', 'type', 'source_id'], 'court_admin_notification_reads_user_id_type_source_id_index');
            $table->foreign(['user_id'], 'court_admin_notification_reads_user_id_foreign')->references(['id'])->on('court_staff')->cascadeOnDelete();
        });

        Schema::create('court_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255)->default('');
            $table->text('content')->nullable();
            $table->string('status', 255)->default('active');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('court_applicants', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 255)->default('');
            $table->string('middle_name', 255)->default('');
            $table->string('last_name', 255)->default('');
            $table->enum('gender', ['male', 'female'])->default('male');
            $table->string('position', 255)->nullable();
            $table->string('organization_name', 255)->nullable();
            $table->boolean('is_lawyer')->default(false);
            $table->string('lawyer_document_path', 255)->nullable();
            $table->string('phone', 255)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('password', 255)->default('');
            $table->string('national_id_number', 255)->nullable();
            $table->text('address')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->char('euisis_employee_uuid', 36)->nullable();
            $table->string('lawyer_license_number', 80)->nullable();
            $table->timestamp('lawyer_verified_at')->nullable();
            $table->unsignedBigInteger('lawyer_verified_by_user_id')->nullable();
            $table->timestamp('lawyer_applied_at')->nullable();
            $table->timestamp('lawyer_refused_at')->nullable();
            $table->text('lawyer_refusal_reason')->nullable();
            $table->unique(['email'], 'court_applicants_email_unique');
            $table->index(['euisis_employee_uuid'], 'court_applicants_euisis_employee_uuid_index');
            $table->index(['lawyer_applied_at'], 'court_applicants_lawyer_applied_at_index');
            $table->unique(['national_id_number'], 'court_applicants_national_id_number_unique');
            $table->unique(['phone'], 'court_applicants_phone_unique');
            $table->index(['email'], 'court_applicants_email_index');
            $table->index(['is_active'], 'court_applicants_is_active_index');
        });

        Schema::create('court_teams', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->default('');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('team_leader_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['parent_id'], 'court_teams_parent_id_index');
            $table->index(['team_leader_id'], 'court_teams_team_leader_id_index');
            $table->foreign(['parent_id'], 'court_teams_parent_id_foreign')->references(['id'])->on('court_teams')->cascadeOnDelete();
            $table->foreign(['team_leader_id'], 'court_teams_team_leader_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
        });

        Schema::create('court_case_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->default('');
            $table->string('prefix', 16)->nullable();
            $table->text('description')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('court_cases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('applicant_id')->nullable();
            $table->string('case_number', 255)->nullable();
            $table->string('code', 8)->nullable();
            $table->string('title', 255)->default('');
            $table->string('respondent_name', 255)->nullable();
            $table->string('respondent_address', 255)->nullable();
            $table->text('description')->nullable();
            $table->text('relief_requested')->nullable();
            $table->unsignedBigInteger('case_type_id');
            $table->unsignedBigInteger('judge_id')->nullable();
            $table->date('filing_date')->nullable();
            $table->date('first_hearing_date')->nullable();
            $table->enum('status', ['pending', 'active', 'adjourned', 'dismissed', 'closed'])->default('pending');
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('review_status', 30)->default('awaiting_review');
            $table->text('review_note')->nullable();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('assigned_team_id')->nullable();
            $table->unsignedBigInteger('assigned_member_user_id')->nullable();
            $table->string('source', 20)->nullable();
            $table->char('euisis_referral_id', 36)->nullable();
            $table->char('respondent_organization_uuid', 36)->nullable();
            $table->char('euisis_filing_id', 36)->nullable();
            $table->unsignedBigInteger('merged_into_case_id')->nullable();
            $table->timestamp('merged_at')->nullable();
            $table->unsignedBigInteger('merged_by_user_id')->nullable();
            $table->text('merge_note')->nullable();
            $table->timestamp('appealed_at')->nullable();
            $table->string('appeal_outcome', 20)->nullable();
            $table->unique(['case_number'], 'court_cases_case_number_unique');
            $table->unique(['code'], 'court_cases_code_unique');
            $table->unique(['euisis_filing_id'], 'court_cases_euisis_filing_id_unique');
            $table->unique(['euisis_referral_id'], 'court_cases_euisis_referral_id_unique');
            $table->index(['judge_id'], 'court_cases_judge_id_index');
            $table->index(['merged_into_case_id'], 'court_cases_merged_into_case_id_index');
            $table->index(['respondent_organization_uuid'], 'court_cases_respondent_organization_uuid_index');
            $table->index(['reviewed_by_user_id'], 'court_cases_reviewed_by_user_id_index');
            $table->index(['review_status'], 'court_cases_review_status_index');
            $table->index(['source'], 'court_cases_source_index');
            $table->index(['applicant_id'], 'court_cases_applicant_id_index');
            $table->index(['assigned_member_user_id'], 'court_cases_assigned_member_user_id_index');
            $table->index(['assigned_team_id'], 'court_cases_assigned_team_id_index');
            $table->index(['assigned_user_id'], 'court_cases_assigned_user_id_index');
            $table->index(['case_type_id'], 'court_cases_case_type_id_index');
            $table->index(['created_at'], 'court_cases_created_at_index');
            $table->index(['status'], 'court_cases_status_index');
            $table->index(['status', 'assigned_user_id'], 'court_cases_status_assigned_user_id_index');
            $table->foreign(['applicant_id'], 'court_cases_applicant_id_foreign')->references(['id'])->on('court_applicants')->nullOnDelete();
            $table->foreign(['assigned_member_user_id'], 'court_cases_assigned_member_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
            $table->foreign(['assigned_team_id'], 'court_cases_assigned_team_id_foreign')->references(['id'])->on('court_teams')->nullOnDelete();
            $table->foreign(['assigned_user_id'], 'court_cases_assigned_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
            $table->foreign(['case_type_id'], 'court_cases_case_type_id_foreign')->references(['id'])->on('court_case_types')->restrictOnDelete();
            $table->foreign(['judge_id'], 'court_cases_judge_id_foreign')->references(['id'])->on('court_staff')->restrictOnDelete();
            $table->foreign(['reviewed_by_user_id'], 'court_cases_reviewed_by_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
        });

        Schema::create('court_appeals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('court_case_id');
            $table->unsignedBigInteger('applicant_id')->nullable();
            $table->string('appellant_type', 20)->nullable();
            $table->string('appellant_name', 255)->nullable();
            $table->string('requested_via', 30)->nullable();
            $table->string('requester_reference', 64)->nullable();
            $table->unsignedBigInteger('decision_id')->nullable();
            $table->json('judge_user_ids')->nullable();
            $table->unsignedBigInteger('submitted_by_user_id')->nullable();
            $table->string('appeal_number', 255)->nullable();
            $table->string('title', 255)->default('');
            $table->text('grounds')->nullable();
            $table->enum('status', ['draft', 'submitted', 'under_review', 'approved', 'rejected', 'closed'])->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->unsignedBigInteger('released_by_user_id')->nullable();
            $table->string('appellate_court', 255)->nullable();
            $table->string('appellate_case_number', 120)->nullable();
            $table->string('outcome', 20)->nullable();
            $table->date('outcome_date')->nullable();
            $table->text('outcome_notes')->nullable();
            $table->string('outcome_document_path', 255)->nullable();
            $table->unsignedBigInteger('outcome_recorded_by_user_id')->nullable();
            $table->timestamp('outcome_recorded_at')->nullable();
            $table->unique(['appeal_number'], 'court_appeals_appeal_number_unique');
            $table->index(['applicant_id'], 'court_appeals_applicant_id_index');
            $table->index(['court_case_id'], 'court_appeals_court_case_id_index');
            $table->index(['court_case_id', 'status'], 'court_appeals_court_case_id_status_index');
            $table->index(['decided_by_user_id'], 'court_appeals_decided_by_user_id_index');
            $table->index(['outcome_date'], 'court_appeals_outcome_date_index');
            $table->index(['outcome_recorded_by_user_id'], 'court_appeals_outcome_recorded_by_user_id_index');
            $table->index(['released_by_user_id'], 'court_appeals_released_by_user_id_index');
            $table->index(['submitted_by_user_id'], 'court_appeals_submitted_by_user_id_index');
            $table->foreign(['applicant_id'], 'court_appeals_applicant_id_foreign')->references(['id'])->on('court_applicants')->nullOnDelete();
            $table->foreign(['court_case_id'], 'court_appeals_court_case_id_foreign')->references(['id'])->on('court_cases')->cascadeOnDelete();
            $table->foreign(['decided_by_user_id'], 'court_appeals_decided_by_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
            $table->foreign(['outcome_recorded_by_user_id'], 'court_appeals_outcome_recorded_by_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
            $table->foreign(['released_by_user_id'], 'court_appeals_released_by_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
            $table->foreign(['submitted_by_user_id'], 'court_appeals_submitted_by_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
        });

        Schema::create('court_appeal_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('appeal_id');
            $table->string('label', 255)->nullable();
            $table->string('path', 255)->default('');
            $table->string('mime', 255)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['appeal_id'], 'court_appeal_documents_appeal_id_index');
            $table->foreign(['appeal_id'], 'court_appeal_documents_appeal_id_foreign')->references(['id'])->on('court_appeals')->cascadeOnDelete();
        });

        Schema::create('court_applicant_notification_prefs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('applicant_id');
            $table->boolean('email_status')->default(true);
            $table->boolean('email_hearing')->default(true);
            $table->boolean('email_message')->default(true);
            $table->boolean('email_weekly_digest')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['applicant_id'], 'court_applicant_notification_prefs_applicant_id_unique');
            $table->foreign(['applicant_id'], 'court_applicant_notification_prefs_applicant_id_foreign')->references(['id'])->on('court_applicants')->cascadeOnDelete();
        });

        Schema::create('court_respondents', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100)->default('');
            $table->string('middle_name', 100)->default('');
            $table->string('last_name', 100)->default('');
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->string('position', 150)->default('');
            $table->string('organization_name', 150)->default('');
            $table->string('address', 255)->default('');
            $table->string('national_id', 16)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 255)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password', 255)->default('');
            $table->string('remember_token', 100)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->char('euisis_organization_uuid', 36)->nullable();
            $table->char('euisis_employee_uuid', 36)->nullable();
            $table->unique(['email'], 'court_respondents_email_unique');
            $table->index(['euisis_employee_uuid'], 'court_respondents_euisis_employee_uuid_index');
            $table->index(['euisis_organization_uuid'], 'court_respondents_euisis_organization_uuid_index');
            $table->unique(['national_id'], 'court_respondents_national_id_unique');
            $table->unique(['phone'], 'court_respondents_phone_unique');
        });

        Schema::create('court_respondent_responses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('respondent_id');
            $table->string('case_number', 255)->nullable();
            $table->string('response_number', 120)->nullable();
            $table->string('title', 255)->default('');
            $table->text('description')->nullable();
            $table->string('pdf_path', 255)->default('');
            $table->string('review_status', 255)->default('awaiting_review');
            $table->text('review_note')->nullable();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['case_number'], 'court_respondent_responses_case_number_index');
            $table->index(['review_status'], 'court_respondent_responses_review_status_index');
            $table->index(['respondent_id'], 'court_respondent_responses_respondent_id_index');
            $table->unique(['response_number'], 'court_respondent_responses_response_number_unique');
            $table->index(['reviewed_by_user_id'], 'court_respondent_responses_reviewed_by_user_id_index');
            $table->foreign(['respondent_id'], 'court_respondent_responses_respondent_id_foreign')->references(['id'])->on('court_respondents')->cascadeOnDelete();
            $table->foreign(['reviewed_by_user_id'], 'court_respondent_responses_reviewed_by_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
        });

        Schema::create('court_applicant_response_replies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('case_id');
            $table->unsignedBigInteger('applicant_id');
            $table->unsignedBigInteger('respondent_response_id');
            $table->text('description')->nullable();
            $table->string('pdf_path', 255)->default('');
            $table->string('review_status', 32)->default('awaiting_review');
            $table->text('review_note')->nullable();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['applicant_id'], 'court_applicant_response_replies_applicant_id_index');
            $table->index(['respondent_response_id'], 'court_applicant_response_replies_8ed695ae54_index');
            $table->index(['reviewed_by_user_id'], 'court_applicant_response_replies_reviewed_by_user_id_index');
            $table->index(['case_id', 'respondent_response_id'], 'court_applicant_response_replies_eb2f03c07c_index');
            $table->foreign(['applicant_id'], 'court_applicant_response_replies_applicant_id_foreign')->references(['id'])->on('court_applicants')->cascadeOnDelete();
            $table->foreign(['case_id'], 'court_applicant_response_replies_case_id_foreign')->references(['id'])->on('court_cases')->cascadeOnDelete();
            $table->foreign(['respondent_response_id'], 'court_applicant_response_replies_d4d25cdb6b_foreign')->references(['id'])->on('court_respondent_responses')->cascadeOnDelete();
            $table->foreign(['reviewed_by_user_id'], 'court_applicant_response_replies_reviewed_by_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
        });

        Schema::create('court_integration_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->nullable();
            $table->char('token_hash', 64)->nullable();
            $table->json('abilities')->nullable();
            $table->json('allowed_ips')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['name'], 'court_integration_clients_name_unique');
            $table->unique(['token_hash'], 'court_integration_clients_token_hash_unique');
        });

        Schema::create('court_applicant_sign_in_tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('applicant_id');
            $table->unsignedBigInteger('integration_client_id')->nullable();
            $table->char('token_hash', 64)->nullable();
            $table->string('target', 120)->default('');
            $table->timestamp('expires_at')->useCurrent();
            $table->timestamp('used_at')->nullable();
            $table->string('used_ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['applicant_id', 'expires_at'], 'court_applicant_sign_in_tickets_039dbd3b94_index');
            $table->index(['integration_client_id'], 'court_applicant_sign_in_tickets_integration_client_id_index');
            $table->unique(['token_hash'], 'court_applicant_sign_in_tickets_token_hash_unique');
            $table->foreign(['applicant_id'], 'court_applicant_sign_in_tickets_applicant_id_foreign')->references(['id'])->on('court_applicants')->cascadeOnDelete();
            $table->foreign(['integration_client_id'], 'court_applicant_sign_in_tickets_72bfbf9a73_foreign')->references(['id'])->on('court_integration_clients')->nullOnDelete();
        });

        Schema::create('court_terms_and_conditions', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255)->default('');
            $table->string('slug', 255)->nullable();
            $table->longText('body')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['slug'], 'court_terms_and_conditions_slug_unique');
        });

        Schema::create('court_applicant_term_acceptances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('applicant_id');
            $table->unsignedBigInteger('terms_and_condition_id');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['applicant_id', 'terms_and_condition_id'], 'court_applicant_term_acceptances_5883b5cbeb_unique');
            $table->index(['terms_and_condition_id'], 'court_applicant_term_acceptances_cd2def2fc1_index');
            $table->foreign(['applicant_id'], 'court_applicant_term_acceptances_applicant_id_foreign')->references(['id'])->on('court_applicants')->cascadeOnDelete();
            $table->foreign(['terms_and_condition_id'], 'court_applicant_term_acceptances_84afd4deb7_foreign')->references(['id'])->on('court_terms_and_conditions')->cascadeOnDelete();
        });

        Schema::create('court_audits', function (Blueprint $table) {
            $table->id();
            $table->string('user_type', 255)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('event', 255)->default('');
            $table->string('auditable_type', 255)->default('');
            $table->unsignedBigInteger('auditable_id')->default(0);
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->text('url')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1023)->nullable();
            $table->string('tags', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['auditable_type', 'auditable_id'], 'court_audits_auditable_type_auditable_id_index');
            $table->index(['user_id', 'user_type'], 'court_audits_user_id_user_type_index');
        });

        Schema::create('court_case_hearings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('case_id');
            $table->dateTime('hearing_at')->nullable();
            $table->string('location', 255)->nullable();
            $table->string('type', 255)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['case_id', 'hearing_at'], 'court_case_hearings_case_id_hearing_at_index');
            $table->foreign(['case_id'], 'court_case_hearings_case_id_foreign')->references(['id'])->on('court_cases')->cascadeOnDelete();
        });

        Schema::create('court_bench_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('case_id');
            $table->unsignedBigInteger('hearing_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('title', 255)->nullable();
            $table->longText('note')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('judge_one_id')->nullable();
            $table->unsignedBigInteger('judge_two_id')->nullable();
            $table->unsignedBigInteger('judge_three_id')->nullable();
            $table->date('note_date')->nullable();
            $table->index(['case_id'], 'court_bench_notes_case_id_index');
            $table->index(['hearing_id'], 'court_bench_notes_hearing_id_index');
            $table->index(['judge_one_id'], 'court_bench_notes_judge_one_id_index');
            $table->index(['judge_three_id'], 'court_bench_notes_judge_three_id_index');
            $table->index(['judge_two_id'], 'court_bench_notes_judge_two_id_index');
            $table->index(['user_id'], 'court_bench_notes_user_id_index');
            $table->foreign(['case_id'], 'court_bench_notes_case_id_foreign')->references(['id'])->on('court_cases')->cascadeOnDelete();
            $table->foreign(['hearing_id'], 'court_bench_notes_hearing_id_foreign')->references(['id'])->on('court_case_hearings')->nullOnDelete();
            $table->foreign(['judge_one_id'], 'court_bench_notes_judge_one_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
            $table->foreign(['judge_three_id'], 'court_bench_notes_judge_three_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
            $table->foreign(['judge_two_id'], 'court_bench_notes_judge_two_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
            $table->foreign(['user_id'], 'court_bench_notes_user_id_foreign')->references(['id'])->on('court_staff')->cascadeOnDelete();
        });

        Schema::create('court_case_applicants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('case_id');
            $table->unsignedBigInteger('applicant_id')->nullable();
            $table->char('euisis_employee_uuid', 36)->nullable();
            $table->string('name', 255)->default('');
            $table->string('role', 20)->default('');
            $table->string('status', 20)->default('');
            $table->string('source', 20)->default('');
            $table->text('statement')->nullable();
            $table->string('attachment_path', 255)->nullable();
            $table->unsignedBigInteger('invited_by_applicant_id')->nullable();
            $table->unsignedBigInteger('merged_from_case_id')->nullable();
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['applicant_id', 'status'], 'court_case_applicants_applicant_id_status_index');
            $table->unique(['case_id', 'euisis_employee_uuid'], 'court_case_applicants_case_id_euisis_employee_uuid_unique');
            $table->index(['case_id', 'role', 'status'], 'court_case_applicants_case_id_role_status_index');
            $table->index(['euisis_employee_uuid', 'status'], 'court_case_applicants_euisis_employee_uuid_status_index');
            $table->foreign(['applicant_id'], 'court_case_applicants_applicant_id_foreign')->references(['id'])->on('court_applicants')->nullOnDelete();
            $table->foreign(['case_id'], 'court_case_applicants_case_id_foreign')->references(['id'])->on('court_cases')->cascadeOnDelete();
        });

        Schema::create('court_case_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('case_id');
            $table->string('action', 100)->default('');
            $table->string('actor_type', 30)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['case_id'], 'court_case_audits_case_id_index');
            $table->foreign(['case_id'], 'court_case_audits_case_id_foreign')->references(['id'])->on('court_cases')->cascadeOnDelete();
        });

        Schema::create('court_case_euisis_grievances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('court_case_id');
            $table->string('kind', 20)->default('grievance');
            $table->char('grievance_uuid', 36)->nullable();
            $table->string('reference', 80)->nullable();
            $table->string('subject', 500)->default('');
            $table->string('category', 255)->nullable();
            $table->string('status', 60)->nullable();
            $table->text('description')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->json('responses')->nullable();
            $table->json('decisions')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['court_case_id', 'grievance_uuid'], 'court_case_euisis_grievances_5a6f865209_unique');
            $table->foreign(['court_case_id'], 'court_case_euisis_grievances_court_case_id_foreign')->references(['id'])->on('court_cases')->cascadeOnDelete();
        });

        Schema::create('court_case_evidences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('case_id');
            $table->enum('type', ['document', 'human'])->default('document');
            $table->string('title', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('path', 255)->nullable();
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('file_path', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['case_id'], 'court_case_evidences_case_id_index');
            $table->foreign(['case_id'], 'court_case_evidences_case_id_foreign')->references(['id'])->on('court_cases')->cascadeOnDelete();
        });

        Schema::create('court_case_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('case_id');
            $table->unsignedBigInteger('uploaded_by_applicant_id')->nullable();
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->string('label', 255)->nullable();
            $table->string('path', 255)->default('');
            $table->string('mime', 255)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['case_id'], 'court_case_files_case_id_index');
            $table->index(['uploaded_by_applicant_id'], 'court_case_files_uploaded_by_applicant_id_index');
            $table->index(['uploaded_by_user_id'], 'court_case_files_uploaded_by_user_id_index');
            $table->foreign(['case_id'], 'court_case_files_case_id_foreign')->references(['id'])->on('court_cases')->cascadeOnDelete();
            $table->foreign(['uploaded_by_applicant_id'], 'court_case_files_uploaded_by_applicant_id_foreign')->references(['id'])->on('court_applicants')->nullOnDelete();
            $table->foreign(['uploaded_by_user_id'], 'court_case_files_uploaded_by_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
        });

        Schema::create('court_case_inspections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('court_case_id');
            $table->date('inspection_date')->nullable();
            $table->string('summary', 255)->default('');
            $table->longText('details')->nullable();
            $table->unsignedBigInteger('inspected_by_user_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['court_case_id'], 'court_case_inspections_court_case_id_index');
            $table->index(['created_by_user_id'], 'court_case_inspections_created_by_user_id_index');
            $table->index(['inspected_by_user_id'], 'court_case_inspections_inspected_by_user_id_index');
            $table->index(['updated_by_user_id'], 'court_case_inspections_updated_by_user_id_index');
            $table->foreign(['court_case_id'], 'court_case_inspections_court_case_id_foreign')->references(['id'])->on('court_cases')->cascadeOnDelete();
            $table->foreign(['created_by_user_id'], 'court_case_inspections_created_by_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
            $table->foreign(['inspected_by_user_id'], 'court_case_inspections_inspected_by_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
            $table->foreign(['updated_by_user_id'], 'court_case_inspections_updated_by_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
        });

        Schema::create('court_case_inspection_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('court_case_id');
            $table->date('request_date')->nullable();
            $table->string('subject', 255)->default('');
            $table->longText('request_note')->nullable();
            $table->string('status', 30)->default('pending');
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->unsignedBigInteger('assigned_inspector_user_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['assigned_inspector_user_id'], 'court_case_inspection_requests_edd08eed69_index');
            $table->index(['court_case_id'], 'court_case_inspection_requests_court_case_id_index');
            $table->index(['created_by_user_id'], 'court_case_inspection_requests_created_by_user_id_index');
            $table->index(['requested_by_user_id'], 'court_case_inspection_requests_requested_by_user_id_index');
            $table->index(['status'], 'court_case_inspection_requests_status_index');
            $table->index(['updated_by_user_id'], 'court_case_inspection_requests_updated_by_user_id_index');
            $table->foreign(['assigned_inspector_user_id'], 'court_case_inspection_requests_053385c208_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
            $table->foreign(['court_case_id'], 'court_case_inspection_requests_court_case_id_foreign')->references(['id'])->on('court_cases')->cascadeOnDelete();
            $table->foreign(['created_by_user_id'], 'court_case_inspection_requests_created_by_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
            $table->foreign(['requested_by_user_id'], 'court_case_inspection_requests_requested_by_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
            $table->foreign(['updated_by_user_id'], 'court_case_inspection_requests_updated_by_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
        });

        Schema::create('court_case_inspection_findings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('case_inspection_request_id');
            $table->date('finding_date')->nullable();
            $table->string('title', 255)->default('');
            $table->longText('details')->nullable();
            $table->string('attachment_path', 255)->nullable();
            $table->string('attachment_original_name', 255)->nullable();
            $table->string('severity', 20)->default('medium');
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedBigInteger('accepted_by_user_id')->nullable();
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['accepted_by_user_id'], 'court_case_inspection_findings_accepted_by_user_id_index');
            $table->index(['case_inspection_request_id'], 'court_case_inspection_findings_6e3077e3c7_index');
            $table->index(['created_by_user_id'], 'court_case_inspection_findings_created_by_user_id_index');
            $table->index(['recorded_by_user_id'], 'court_case_inspection_findings_recorded_by_user_id_index');
            $table->index(['severity'], 'court_case_inspection_findings_severity_index');
            $table->index(['updated_by_user_id'], 'court_case_inspection_findings_updated_by_user_id_index');
            $table->foreign(['accepted_by_user_id'], 'court_case_inspection_findings_accepted_by_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
            $table->foreign(['case_inspection_request_id'], 'court_case_inspection_findings_ee984d8a9f_foreign')->references(['id'])->on('court_case_inspection_requests')->cascadeOnDelete();
            $table->foreign(['created_by_user_id'], 'court_case_inspection_findings_created_by_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
            $table->foreign(['recorded_by_user_id'], 'court_case_inspection_findings_recorded_by_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
            $table->foreign(['updated_by_user_id'], 'court_case_inspection_findings_updated_by_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
        });

        Schema::create('court_case_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('case_id');
            $table->unsignedBigInteger('sender_applicant_id')->nullable();
            $table->unsignedBigInteger('sender_user_id')->nullable();
            $table->text('body')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['sender_applicant_id'], 'court_case_messages_sender_applicant_id_index');
            $table->index(['sender_user_id'], 'court_case_messages_sender_user_id_index');
            $table->index(['case_id', 'created_at'], 'court_case_messages_case_id_created_at_index');
            $table->foreign(['case_id'], 'court_case_messages_case_id_foreign')->references(['id'])->on('court_cases')->cascadeOnDelete();
            $table->foreign(['sender_applicant_id'], 'court_case_messages_sender_applicant_id_foreign')->references(['id'])->on('court_applicants')->nullOnDelete();
            $table->foreign(['sender_user_id'], 'court_case_messages_sender_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
        });

        Schema::create('court_case_parties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('court_case_id');
            $table->unsignedBigInteger('user_id');
            $table->enum('party_type', ['plaintiff', 'defendant', 'lawyer', 'witness', 'judge'])->default('plaintiff');
            $table->text('representation')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['court_case_id'], 'court_case_parties_court_case_id_index');
            $table->index(['user_id'], 'court_case_parties_user_id_index');
            $table->foreign(['court_case_id'], 'court_case_parties_court_case_id_foreign')->references(['id'])->on('court_cases')->cascadeOnDelete();
            $table->foreign(['user_id'], 'court_case_parties_user_id_foreign')->references(['id'])->on('court_staff')->cascadeOnDelete();
        });

        Schema::create('court_case_representatives', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('case_id');
            $table->unsignedBigInteger('case_applicant_id');
            $table->unsignedBigInteger('lawyer_applicant_id');
            $table->string('power_of_attorney_path', 255)->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('confirmed_by_party_at')->nullable();
            $table->unsignedBigInteger('verified_by_user_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['case_id'], 'court_case_representatives_case_id_index');
            $table->index(['lawyer_applicant_id', 'status'], 'court_case_representatives_lawyer_applicant_id_status_index');
            $table->unique(['case_applicant_id', 'lawyer_applicant_id'], 'court_case_representatives_4d0c7af83e_unique');
            $table->foreign(['case_applicant_id'], 'court_case_representatives_case_applicant_id_foreign')->references(['id'])->on('court_case_applicants')->cascadeOnDelete();
            $table->foreign(['case_id'], 'court_case_representatives_case_id_foreign')->references(['id'])->on('court_cases')->cascadeOnDelete();
            $table->foreign(['lawyer_applicant_id'], 'court_case_representatives_lawyer_applicant_id_foreign')->references(['id'])->on('court_applicants')->cascadeOnDelete();
        });

        Schema::create('court_case_status_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('case_id');
            $table->string('from_status', 255)->nullable();
            $table->string('to_status', 255)->default('');
            $table->unsignedBigInteger('changed_by_user_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['changed_by_user_id'], 'court_case_status_logs_changed_by_user_id_index');
            $table->index(['case_id', 'created_at'], 'court_case_status_logs_case_id_created_at_index');
            $table->index(['case_id'], 'court_case_status_logs_case_id_index');
            $table->foreign(['case_id'], 'court_case_status_logs_case_id_foreign')->references(['id'])->on('court_cases')->cascadeOnDelete();
            $table->foreign(['changed_by_user_id'], 'court_case_status_logs_changed_by_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
        });

        Schema::create('court_case_witnesses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('case_id');
            $table->string('full_name', 255)->default('');
            $table->string('phone', 60)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 255)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['case_id'], 'court_case_witnesses_case_id_index');
            $table->index(['created_by_user_id'], 'court_case_witnesses_created_by_user_id_index');
            $table->index(['updated_by_user_id'], 'court_case_witnesses_updated_by_user_id_index');
            $table->foreign(['case_id'], 'court_case_witnesses_case_id_foreign')->references(['id'])->on('court_cases')->cascadeOnDelete();
            $table->foreign(['created_by_user_id'], 'court_case_witnesses_created_by_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
            $table->foreign(['updated_by_user_id'], 'court_case_witnesses_updated_by_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
        });

        Schema::create('court_courts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->default('');
            $table->string('type', 255)->default('');
            $table->string('location', 255)->default('');
            $table->text('address')->nullable();
            $table->string('contact_info', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('court_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('name', 255)->default('');
            $table->string('role', 255)->nullable();
            $table->text('bio')->nullable();
            $table->string('photo_path', 255)->nullable();
            $table->json('translations')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['is_active', 'sort_order'], 'court_members_is_active_sort_order_index');
        });

        Schema::create('court_decisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('court_case_id');
            $table->string('case_number', 255)->default('');
            $table->string('applicant_full_name', 255)->nullable();
            $table->string('respondent_full_name', 255)->nullable();
            $table->date('case_filed_date')->nullable();
            $table->date('decision_date')->nullable();
            $table->unsignedBigInteger('reviewing_admin_user_id')->nullable();
            $table->string('reviewing_admin_user_name', 255)->nullable();
            $table->json('reviewing_admin_user_names')->nullable();
            $table->string('name', 255)->default('');
            $table->text('description')->nullable();
            $table->text('decision_content')->nullable();
            $table->string('status', 32)->default('draft');
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->string('case_file_number', 255)->nullable();
            $table->json('panel_judges')->nullable();
            $table->string('panel_decision', 32)->default('pending');
            $table->text('judges_comments')->nullable();
            $table->index(['approved_by'], 'court_decisions_approved_by_index');
            $table->index(['court_case_id', 'decision_date'], 'court_decisions_court_case_id_decision_date_index');
            $table->index(['reviewing_admin_user_id'], 'court_decisions_reviewing_admin_user_id_index');
            $table->foreign(['approved_by'], 'court_decisions_approved_by_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
            $table->foreign(['court_case_id'], 'court_decisions_court_case_id_foreign')->references(['id'])->on('court_cases')->cascadeOnDelete();
            $table->foreign(['reviewing_admin_user_id'], 'court_decisions_reviewing_admin_user_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
        });

        Schema::create('court_decision_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('decision_id');
            $table->string('case_number', 255)->nullable();
            $table->unsignedBigInteger('reviewer_id')->nullable();
            $table->text('review_note')->nullable();
            $table->string('outcome', 32)->default('pending');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['decision_id', 'outcome'], 'court_decision_reviews_decision_id_outcome_index');
            $table->index(['reviewer_id'], 'court_decision_reviews_reviewer_id_index');
            $table->foreign(['decision_id'], 'court_decision_reviews_decision_id_foreign')->references(['id'])->on('court_decisions')->cascadeOnDelete();
            $table->foreign(['reviewer_id'], 'court_decision_reviews_reviewer_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
        });

        Schema::create('court_decision_templates', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255)->default('');
            $table->string('category', 255)->nullable();
            $table->boolean('is_default')->default(false);
            $table->json('placeholders')->nullable();
            $table->longText('body')->nullable();
            $table->string('header_image_path', 255)->nullable();
            $table->string('footer_image_path', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('court_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('court_case_id');
            $table->string('title', 255)->default('');
            $table->string('file_path', 255)->default('');
            $table->string('file_type', 255)->nullable();
            $table->integer('file_size')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['court_case_id'], 'court_documents_court_case_id_index');
            $table->index(['uploaded_by'], 'court_documents_uploaded_by_index');
            $table->foreign(['court_case_id'], 'court_documents_court_case_id_foreign')->references(['id'])->on('court_cases')->cascadeOnDelete();
            $table->foreign(['uploaded_by'], 'court_documents_uploaded_by_foreign')->references(['id'])->on('court_staff')->restrictOnDelete();
        });

        Schema::create('court_euisis_referrals', function (Blueprint $table) {
            $table->id();
            $table->char('referral_uuid', 36)->nullable();
            $table->unsignedBigInteger('court_case_id');
            $table->unsignedBigInteger('integration_client_id')->nullable();
            $table->string('grievance_reference', 80)->nullable();
            $table->string('tribunal_case_number', 80)->nullable();
            $table->string('complainant_name', 255)->nullable();
            $table->char('complainant_employee_uuid', 36)->nullable();
            $table->string('complainant_phone', 40)->nullable();
            $table->string('complainant_email', 255)->nullable();
            $table->char('respondent_organization_uuid', 36)->nullable();
            $table->string('referring_officer', 255)->nullable();
            $table->json('payload')->nullable();
            $table->json('last_reported_state')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['court_case_id'], 'court_euisis_referrals_court_case_id_index');
            $table->index(['integration_client_id'], 'court_euisis_referrals_integration_client_id_index');
            $table->unique(['referral_uuid'], 'court_euisis_referrals_referral_uuid_unique');
            $table->foreign(['court_case_id'], 'court_euisis_referrals_court_case_id_foreign')->references(['id'])->on('court_cases')->cascadeOnDelete();
            $table->foreign(['integration_client_id'], 'court_euisis_referrals_integration_client_id_foreign')->references(['id'])->on('court_integration_clients')->nullOnDelete();
        });

        Schema::create('court_euisis_case_updates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('euisis_referral_id');
            $table->unsignedBigInteger('court_case_id');
            $table->string('case_number', 80)->default('');
            $table->string('review_status', 30)->nullable();
            $table->text('review_reason')->nullable();
            $table->string('status', 30)->nullable();
            $table->dateTime('next_hearing_at')->nullable();
            $table->date('decision_date')->nullable();
            $table->text('decision_summary')->nullable();
            $table->json('changed')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['court_case_id', 'id'], 'court_euisis_case_updates_court_case_id_id_index');
            $table->index(['euisis_referral_id'], 'court_euisis_case_updates_euisis_referral_id_index');
            $table->foreign(['court_case_id'], 'court_euisis_case_updates_court_case_id_foreign')->references(['id'])->on('court_cases')->cascadeOnDelete();
            $table->foreign(['euisis_referral_id'], 'court_euisis_case_updates_euisis_referral_id_foreign')->references(['id'])->on('court_euisis_referrals')->cascadeOnDelete();
        });

        Schema::create('court_euisis_employees', function (Blueprint $table) {
            $table->char('uuid', 36)->default('');
            $table->string('employee_number', 60)->nullable();
            $table->string('full_name', 255)->nullable();
            $table->string('full_name_en', 255)->nullable();
            $table->string('status', 40)->nullable();
            $table->char('organization_uuid', 36)->nullable();
            $table->char('unit_uuid', 36)->nullable();
            $table->char('position_uuid', 36)->nullable();
            $table->char('assignment_uuid', 36)->nullable();
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['uuid'], 'court_euisis_employees_uuid_primary');
            $table->index(['employee_number'], 'court_euisis_employees_employee_number_index');
        });

        Schema::create('court_letter_templates', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255)->default('');
            $table->string('category', 255)->nullable();
            $table->string('subject_prefix', 80)->nullable();
            $table->unsignedInteger('reference_sequence')->default(0);
            $table->json('placeholders')->nullable();
            $table->longText('body')->nullable();
            $table->string('header_image_path', 255)->nullable();
            $table->string('footer_image_path', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('court_letters', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number', 50)->nullable();
            $table->string('case_number', 60)->nullable();
            $table->unsignedBigInteger('letter_template_id');
            $table->unsignedBigInteger('user_id');
            $table->string('recipient_name', 255)->default('');
            $table->string('recipient_title', 255)->nullable();
            $table->string('recipient_company', 255)->nullable();
            $table->string('subject', 255)->nullable();
            $table->longText('body')->nullable();
            $table->string('cc', 255)->nullable();
            $table->boolean('send_to_applicant')->default(true);
            $table->boolean('send_to_respondent')->default(true);
            $table->string('approved_by_name', 255)->nullable();
            $table->string('approved_by_title', 255)->nullable();
            $table->string('approval_status', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['approval_status'], 'court_letters_approval_status_index');
            $table->index(['case_number'], 'court_letters_case_number_index');
            $table->index(['letter_template_id'], 'court_letters_letter_template_id_index');
            $table->unique(['reference_number'], 'court_letters_reference_number_unique');
            $table->index(['user_id'], 'court_letters_user_id_index');
            $table->foreign(['letter_template_id'], 'court_letters_letter_template_id_foreign')->references(['id'])->on('court_letter_templates')->cascadeOnDelete();
            $table->foreign(['user_id'], 'court_letters_user_id_foreign')->references(['id'])->on('court_staff')->cascadeOnDelete();
        });

        Schema::create('court_euisis_letter_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('letter_id');
            $table->unsignedBigInteger('court_case_id')->nullable();
            $table->char('organization_uuid', 36)->default('');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['court_case_id'], 'court_euisis_letter_deliveries_court_case_id_index');
            $table->unique(['letter_id'], 'court_euisis_letter_deliveries_letter_id_unique');
            $table->index(['organization_uuid'], 'court_euisis_letter_deliveries_organization_uuid_index');
            $table->foreign(['court_case_id'], 'court_euisis_letter_deliveries_court_case_id_foreign')->references(['id'])->on('court_cases')->nullOnDelete();
            $table->foreign(['letter_id'], 'court_euisis_letter_deliveries_letter_id_foreign')->references(['id'])->on('court_letters')->cascadeOnDelete();
        });

        Schema::create('court_euisis_organizations', function (Blueprint $table) {
            $table->char('uuid', 36)->default('');
            $table->string('code', 60)->nullable();
            $table->string('name_en', 255)->default('');
            $table->string('name_am', 255)->nullable();
            $table->string('status', 40)->nullable();
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['uuid'], 'court_euisis_organizations_uuid_primary');
            $table->index(['status'], 'court_euisis_organizations_status_index');
        });

        Schema::create('court_euisis_organization_units', function (Blueprint $table) {
            $table->char('uuid', 36)->default('');
            $table->char('organization_uuid', 36)->default('');
            $table->char('parent_unit_uuid', 36)->nullable();
            $table->string('code', 60)->nullable();
            $table->string('name_en', 255)->default('');
            $table->string('name_am', 255)->nullable();
            $table->string('status', 40)->nullable();
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['uuid'], 'court_euisis_organization_units_uuid_primary');
            $table->index(['organization_uuid'], 'court_euisis_organization_units_organization_uuid_index');
        });

        Schema::create('court_euisis_positions', function (Blueprint $table) {
            $table->char('uuid', 36)->default('');
            $table->char('organization_uuid', 36)->default('');
            $table->char('unit_uuid', 36)->nullable();
            $table->string('code', 60)->nullable();
            $table->string('title_en', 255)->default('');
            $table->string('title_am', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['uuid'], 'court_euisis_positions_uuid_primary');
            $table->index(['organization_uuid'], 'court_euisis_positions_organization_uuid_index');
        });

        Schema::create('court_euisis_staff_notices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('event', 20)->default('');
            $table->string('reason', 40)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['created_at'], 'court_euisis_staff_notices_created_at_index');
            $table->index(['user_id'], 'court_euisis_staff_notices_user_id_index');
            $table->foreign(['user_id'], 'court_euisis_staff_notices_user_id_foreign')->references(['id'])->on('court_staff')->cascadeOnDelete();
        });

        Schema::create('court_euisis_staff_units', function (Blueprint $table) {
            $table->char('unit_uuid', 36)->default('');
            $table->char('organization_uuid', 36)->default('');
            $table->boolean('include_sub_units')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['unit_uuid'], 'court_euisis_staff_units_unit_uuid_primary');
            $table->index(['organization_uuid'], 'court_euisis_staff_units_organization_uuid_index');
        });

        Schema::create('court_euisis_sync_state', function (Blueprint $table) {
            $table->string('key', 60)->default('');
            $table->text('value')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['key'], 'court_euisis_sync_state_key_primary');
        });

        Schema::create('court_file_security_records', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 36)->nullable();
            $table->string('disk', 64)->default('');
            $table->string('path', 255)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('detected_mime', 191)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('scan_status', 32)->default('not_configured');
            $table->string('scanner', 64)->nullable();
            $table->text('scan_message')->nullable();
            $table->string('related_type', 100)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->unsignedBigInteger('uploaded_by_applicant_id')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['path'], 'court_file_security_records_path_unique');
            $table->unique(['public_id'], 'court_file_security_records_public_id_unique');
            $table->index(['related_type', 'related_id'], 'court_file_security_records_related_type_related_id_index');
            $table->index(['scan_status'], 'court_file_security_records_scan_status_index');
            $table->index(['sha256'], 'court_file_security_records_sha256_index');
            $table->index(['uploaded_by_applicant_id'], 'court_file_security_records_uploaded_by_applicant_id_index');
            $table->index(['uploaded_by_user_id'], 'court_file_security_records_uploaded_by_user_id_index');
        });

        Schema::create('court_hearings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('court_case_id');
            $table->date('hearing_date')->nullable();
            $table->time('hearing_time')->nullable();
            $table->string('location', 255)->nullable();
            $table->text('purpose')->nullable();
            $table->text('outcome')->nullable();
            $table->enum('status', ['scheduled', 'completed', 'adjourned', 'cancelled'])->default('scheduled');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['court_case_id'], 'court_hearings_court_case_id_index');
            $table->foreign(['court_case_id'], 'court_hearings_court_case_id_foreign')->references(['id'])->on('court_cases')->cascadeOnDelete();
        });

        Schema::create('court_home_faqs', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('question')->nullable();
            $table->text('answer')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->json('translations')->nullable();
        });

        Schema::create('court_home_resources', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->string('type', 20)->default('post');
            $table->string('title', 255)->default('');
            $table->text('description')->nullable();
            $table->string('file_path', 500)->nullable();
            $table->string('external_url', 500)->nullable();
            $table->string('cover_image', 500)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->json('translations')->nullable();
        });

        Schema::create('court_home_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('title', 255)->default('');
            $table->text('description')->nullable();
            $table->string('meta', 120)->nullable();
            $table->json('features')->nullable();
            $table->string('icon_type', 30)->default('document');
            $table->string('accent', 30)->default('blue');
            $table->string('href', 500)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->json('translations')->nullable();
        });

        Schema::create('court_home_settings', function (Blueprint $table) {
            $table->string('key', 80)->default('');
            $table->longText('value')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['key'], 'court_home_settings_key_primary');
        });

        Schema::create('court_home_slides', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('badge', 120)->nullable();
            $table->string('title', 255)->default('');
            $table->text('description')->nullable();
            $table->string('primary_label', 120)->default('');
            $table->string('primary_href', 500)->default('#');
            $table->string('secondary_label', 120)->nullable();
            $table->string('secondary_href', 500)->nullable();
            $table->string('bg_style', 20)->default('blue');
            $table->string('bg_image', 500)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->json('translations')->nullable();
        });

        Schema::create('court_home_timeline_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('title', 255)->default('');
            $table->text('description')->nullable();
            $table->string('meta', 120)->nullable();
            $table->string('duration', 60)->nullable();
            $table->string('color', 30)->default('blue');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->json('translations')->nullable();
        });

        Schema::create('court_legal_holds', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 36)->nullable();
            $table->string('holdable_type', 100)->default('');
            $table->unsignedBigInteger('holdable_id')->default(0);
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('placed_by_user_id')->nullable();
            $table->timestamp('placed_at')->useCurrent();
            $table->unsignedBigInteger('released_by_user_id')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('release_reason')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['holdable_type', 'holdable_id', 'released_at'], 'court_legal_holds_f8cb7f4ab4_index');
            $table->index(['placed_by_user_id'], 'court_legal_holds_placed_by_user_id_index');
            $table->unique(['public_id'], 'court_legal_holds_public_id_unique');
            $table->index(['released_by_user_id'], 'court_legal_holds_released_by_user_id_index');
        });

        Schema::create('court_letter_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->nullable();
            $table->string('description', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['name'], 'court_letter_categories_name_unique');
        });

        Schema::create('court_notification_reads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('applicant_id')->nullable();
            $table->string('type', 20)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['applicant_id', 'type', 'source_id'], 'court_notification_reads_applicant_id_type_source_id_index');
            $table->index(['applicant_id', 'type'], 'court_notification_reads_applicant_id_type_index');
            $table->unique(['applicant_id', 'type', 'source_id'], 'court_notification_reads_applicant_id_type_source_id_unique');
        });

        Schema::create('court_password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 255)->default('');
            $table->string('token', 255)->default('');
            $table->timestamp('created_at')->nullable();
            $table->primary(['email'], 'court_password_reset_tokens_email_primary');
        });

        Schema::create('court_performance_evaluations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('evaluated_user_id');
            $table->unsignedBigInteger('evaluator_id')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->enum('period_type', ['monthly', 'quarterly', 'annual'])->default('monthly');
            $table->decimal('overall_score', 5, 2)->default(0.00);
            $table->enum('status', ['draft', 'submitted', 'reviewed'])->default('draft');
            $table->text('notes')->nullable();
            $table->text('reviewer_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['evaluated_user_id'], 'court_performance_evaluations_evaluated_user_id_index');
            $table->index(['evaluator_id'], 'court_performance_evaluations_evaluator_id_index');
            $table->index(['period_start'], 'court_performance_evaluations_period_start_index');
            $table->index(['reviewed_by'], 'court_performance_evaluations_reviewed_by_index');
            $table->index(['status'], 'court_performance_evaluations_status_index');
            $table->foreign(['evaluated_user_id'], 'court_performance_evaluations_evaluated_user_id_foreign')->references(['id'])->on('court_staff')->cascadeOnDelete();
            $table->foreign(['evaluator_id'], 'court_performance_evaluations_evaluator_id_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
            $table->foreign(['reviewed_by'], 'court_performance_evaluations_reviewed_by_foreign')->references(['id'])->on('court_staff')->nullOnDelete();
        });

        Schema::create('court_performance_evaluation_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 255)->nullable();
            $table->string('name', 255)->default('');
            $table->string('name_am', 255)->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['slug'], 'court_performance_evaluation_categories_slug_unique');
        });

        Schema::create('court_performance_evaluation_criteria', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->default('');
            $table->string('name_am', 255)->nullable();
            $table->string('category', 255)->default('general');
            $table->string('source', 40)->nullable();
            $table->unsignedTinyInteger('weight')->default(10);
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('court_performance_evaluation_scores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('evaluation_id');
            $table->unsignedBigInteger('criterion_id');
            $table->unsignedTinyInteger('score')->default(0);
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['criterion_id'], 'court_performance_evaluation_scores_criterion_id_index');
            $table->unique(['evaluation_id', 'criterion_id'], 'court_performance_evaluation_scores_e912d58d37_unique');
            $table->foreign(['criterion_id'], 'court_performance_evaluation_scores_criterion_id_foreign')->references(['id'])->on('court_performance_evaluation_criteria')->cascadeOnDelete();
            $table->foreign(['evaluation_id'], 'court_performance_evaluation_scores_evaluation_id_foreign')->references(['id'])->on('court_performance_evaluations')->cascadeOnDelete();
        });

        Schema::create('court_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->nullable();
            $table->string('label', 255)->nullable();
            $table->json('label_translations')->nullable();
            $table->string('description', 255)->nullable();
            $table->json('description_translations')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['name'], 'court_permissions_name_unique');
        });

        Schema::create('court_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->nullable();
            $table->string('description', 255)->nullable();
            $table->boolean('mfa_required')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['name'], 'court_roles_name_unique');
        });

        Schema::create('court_permission_role', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['permission_id', 'role_id'], 'court_permission_role_permission_id_role_id_unique');
            $table->index(['role_id'], 'court_permission_role_role_id_index');
            $table->foreign(['permission_id'], 'court_permission_role_permission_id_foreign')->references(['id'])->on('court_permissions')->cascadeOnDelete();
            $table->foreign(['role_id'], 'court_permission_role_role_id_foreign')->references(['id'])->on('court_roles')->cascadeOnDelete();
        });

        Schema::create('court_respondent_case_views', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('respondent_id');
            $table->unsignedBigInteger('case_id');
            $table->string('case_number', 60)->nullable();
            $table->timestamp('viewed_at')->useCurrent();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['respondent_id'], 'court_respondent_case_views_respondent_id_index');
            $table->index(['case_id', 'viewed_at'], 'court_respondent_case_views_case_id_viewed_at_index');
            $table->unique(['respondent_id', 'case_id'], 'court_respondent_case_views_respondent_id_case_id_unique');
            $table->foreign(['case_id'], 'court_respondent_case_views_case_id_foreign')->references(['id'])->on('court_cases')->cascadeOnDelete();
            $table->foreign(['respondent_id'], 'court_respondent_case_views_respondent_id_foreign')->references(['id'])->on('court_respondents')->cascadeOnDelete();
        });

        Schema::create('court_respondent_notification_reads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('respondent_id')->nullable();
            $table->string('type', 32)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['respondent_id'], 'court_respondent_notification_reads_respondent_id_index');
            $table->unique(['respondent_id', 'type', 'source_id'], 'court_respondent_notification_reads_cace316dd4_unique');
        });

        Schema::create('court_role_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['role_id'], 'court_role_user_role_id_index');
            $table->unique(['user_id', 'role_id'], 'court_role_user_user_id_role_id_unique');
            $table->foreign(['role_id'], 'court_role_user_role_id_foreign')->references(['id'])->on('court_roles')->cascadeOnDelete();
            $table->foreign(['user_id'], 'court_role_user_user_id_foreign')->references(['id'])->on('court_staff')->cascadeOnDelete();
        });

        Schema::create('court_system_audits', function (Blueprint $table) {
            $table->id();
            $table->string('request_id', 128)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('actor_type', 255)->default('user');
            $table->string('action', 255)->nullable();
            $table->string('outcome', 32)->default('success');
            $table->string('module', 255)->nullable();
            $table->string('route', 255)->nullable();
            $table->string('method', 10)->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['outcome'], 'court_system_audits_outcome_index');
            $table->index(['request_id'], 'court_system_audits_request_id_index');
            $table->index(['user_id'], 'court_system_audits_user_id_index');
        });

        Schema::create('court_system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('app_name', 255)->default('Court Case Management System');
            $table->string('short_name', 255)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->string('banner_path', 255)->nullable();
            $table->string('favicon_path', 255)->nullable();
            $table->string('seal_path', 255)->nullable();
            $table->string('welcome_message', 255)->nullable();
            $table->text('about')->nullable();
            $table->text('footer_text')->nullable();
            $table->string('address', 255)->nullable();
            $table->string('website_url', 255)->nullable();
            $table->string('default_locale', 10)->default('en');
            $table->boolean('show_language_switcher')->default(true);
            $table->string('timezone', 60)->default('Africa/Addis_Ababa');
            $table->string('date_format', 30)->default('Y-m-d');
            $table->string('time_format', 10)->default('H:i');
            $table->boolean('use_ethiopian_calendar')->default(false);
            $table->unsignedSmallInteger('session_lifetime')->default(120);
            $table->unsignedSmallInteger('session_warning_minutes')->default(5);
            $table->boolean('session_extend_on_activity')->default(true);
            $table->unsignedTinyInteger('password_min_length')->default(8);
            $table->boolean('password_require_uppercase')->default(true);
            $table->boolean('password_require_number')->default(true);
            $table->boolean('password_require_symbol')->default(false);
            $table->unsignedTinyInteger('login_max_attempts')->default(5);
            $table->unsignedSmallInteger('lockout_minutes')->default(15);
            $table->boolean('force_https')->default(false);
            $table->boolean('mfa_enabled')->default(false);
            $table->boolean('audit_logging_enabled')->default(true);
            $table->unsignedSmallInteger('audit_retention_days')->default(365);
            $table->boolean('registration_open')->default(true);
            $table->string('accent_palette', 20)->default('blue');
            $table->string('default_theme', 10)->default('system');
            $table->boolean('show_banner_on_login')->default(true);
            $table->text('custom_css')->nullable();
            $table->boolean('api_enabled')->default(false);
            $table->unsignedSmallInteger('api_rate_limit')->default(60);
            $table->boolean('mail_enabled')->default(false);
            $table->string('mail_mailer', 20)->default('smtp');
            $table->string('mail_host', 255)->nullable();
            $table->unsignedSmallInteger('mail_port')->default(587);
            $table->string('mail_username', 255)->nullable();
            $table->text('mail_password')->nullable();
            $table->string('mail_encryption', 10)->default('tls');
            $table->string('mail_from_address', 255)->nullable();
            $table->string('mail_from_name', 255)->nullable();
            $table->boolean('telegram_enabled')->default(false);
            $table->text('telegram_bot_token')->nullable();
            $table->string('telegram_default_chat_id', 255)->nullable();
            $table->boolean('sms_enabled')->default(false);
            $table->string('sms_provider', 30)->default('infobip');
            $table->text('sms_api_key')->nullable();
            $table->string('sms_api_secret', 255)->nullable();
            $table->string('sms_sender_id', 20)->nullable();
            $table->string('sms_base_url', 255)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 255)->nullable();
            $table->boolean('maintenance_mode')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->string('primary_color', 7)->nullable();
            $table->string('secondary_color', 7)->nullable();
            $table->string('accent_color', 7)->nullable();
            $table->string('sidebar_color', 7)->nullable();
            $table->string('table_density', 20)->nullable();
            $table->string('button_style', 20)->nullable();
            $table->string('card_radius', 20)->nullable();
            $table->string('logo_position', 20)->nullable();
            $table->boolean('allow_user_theme_switching')->nullable();
            $table->boolean('enable_ui_animations')->nullable();
            $table->boolean('sidebar_compact_default')->nullable();
            $table->boolean('show_breadcrumbs')->nullable();
            $table->boolean('sticky_table_headers')->nullable();
        });

        Schema::create('court_team_user', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['team_id', 'user_id'], 'court_team_user_team_id_user_id_primary');
            $table->index(['team_id'], 'court_team_user_team_id_index');
            $table->index(['user_id'], 'court_team_user_user_id_index');
            $table->unique(['user_id'], 'court_team_user_user_id_unique');
            $table->foreign(['team_id'], 'court_team_user_team_id_foreign')->references(['id'])->on('court_teams')->cascadeOnDelete();
            $table->foreign(['user_id'], 'court_team_user_user_id_foreign')->references(['id'])->on('court_staff')->cascadeOnDelete();
        });

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('court_team_user');
        Schema::dropIfExists('court_system_settings');
        Schema::dropIfExists('court_system_audits');
        Schema::dropIfExists('court_role_user');
        Schema::dropIfExists('court_respondent_notification_reads');
        Schema::dropIfExists('court_respondent_case_views');
        Schema::dropIfExists('court_permission_role');
        Schema::dropIfExists('court_roles');
        Schema::dropIfExists('court_permissions');
        Schema::dropIfExists('court_performance_evaluation_scores');
        Schema::dropIfExists('court_performance_evaluation_criteria');
        Schema::dropIfExists('court_performance_evaluation_categories');
        Schema::dropIfExists('court_performance_evaluations');
        Schema::dropIfExists('court_password_reset_tokens');
        Schema::dropIfExists('court_notification_reads');
        Schema::dropIfExists('court_letter_categories');
        Schema::dropIfExists('court_legal_holds');
        Schema::dropIfExists('court_home_timeline_steps');
        Schema::dropIfExists('court_home_slides');
        Schema::dropIfExists('court_home_settings');
        Schema::dropIfExists('court_home_services');
        Schema::dropIfExists('court_home_resources');
        Schema::dropIfExists('court_home_faqs');
        Schema::dropIfExists('court_hearings');
        Schema::dropIfExists('court_file_security_records');
        Schema::dropIfExists('court_euisis_sync_state');
        Schema::dropIfExists('court_euisis_staff_units');
        Schema::dropIfExists('court_euisis_staff_notices');
        Schema::dropIfExists('court_euisis_positions');
        Schema::dropIfExists('court_euisis_organization_units');
        Schema::dropIfExists('court_euisis_organizations');
        Schema::dropIfExists('court_euisis_letter_deliveries');
        Schema::dropIfExists('court_letters');
        Schema::dropIfExists('court_letter_templates');
        Schema::dropIfExists('court_euisis_employees');
        Schema::dropIfExists('court_euisis_case_updates');
        Schema::dropIfExists('court_euisis_referrals');
        Schema::dropIfExists('court_documents');
        Schema::dropIfExists('court_decision_templates');
        Schema::dropIfExists('court_decision_reviews');
        Schema::dropIfExists('court_decisions');
        Schema::dropIfExists('court_members');
        Schema::dropIfExists('court_courts');
        Schema::dropIfExists('court_case_witnesses');
        Schema::dropIfExists('court_case_status_logs');
        Schema::dropIfExists('court_case_representatives');
        Schema::dropIfExists('court_case_parties');
        Schema::dropIfExists('court_case_messages');
        Schema::dropIfExists('court_case_inspection_findings');
        Schema::dropIfExists('court_case_inspection_requests');
        Schema::dropIfExists('court_case_inspections');
        Schema::dropIfExists('court_case_files');
        Schema::dropIfExists('court_case_evidences');
        Schema::dropIfExists('court_case_euisis_grievances');
        Schema::dropIfExists('court_case_audits');
        Schema::dropIfExists('court_case_applicants');
        Schema::dropIfExists('court_bench_notes');
        Schema::dropIfExists('court_case_hearings');
        Schema::dropIfExists('court_audits');
        Schema::dropIfExists('court_applicant_term_acceptances');
        Schema::dropIfExists('court_terms_and_conditions');
        Schema::dropIfExists('court_applicant_sign_in_tickets');
        Schema::dropIfExists('court_integration_clients');
        Schema::dropIfExists('court_applicant_response_replies');
        Schema::dropIfExists('court_respondent_responses');
        Schema::dropIfExists('court_respondents');
        Schema::dropIfExists('court_applicant_notification_prefs');
        Schema::dropIfExists('court_appeal_documents');
        Schema::dropIfExists('court_appeals');
        Schema::dropIfExists('court_cases');
        Schema::dropIfExists('court_case_types');
        Schema::dropIfExists('court_teams');
        Schema::dropIfExists('court_applicants');
        Schema::dropIfExists('court_announcements');
        Schema::dropIfExists('court_admin_notification_reads');
        Schema::dropIfExists('court_about_pages');
        Schema::dropIfExists('court_staff');

        Schema::enableForeignKeyConstraints();
    }
};
