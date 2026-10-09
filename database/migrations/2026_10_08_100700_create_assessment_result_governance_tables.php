<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Immutable competency-result revisions and their three separate governance processes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_result_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('assessment_record_id')->constrained('assessment_records')->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->decimal('raw_score', 12, 4)->nullable();
            $table->decimal('normalized_score', 12, 4)->nullable();
            $table->decimal('contribution_score', 12, 4)->nullable();
            $table->uuid('result_band_policy_id')->nullable();
            $table->string('result_band_code', 40)->nullable();
            $table->string('result_band_label_en')->nullable();
            $table->string('result_band_label_am')->nullable();
            // original | appeal_decision | moderation | technical_correction
            $table->string('change_type', 32);
            // Self-reference: the key is added below, once the primary key exists.
            // Declared inline, PostgreSQL received the foreign key before the
            // primary key and refused it (no unique constraint on id yet).
            $table->uuid('supersedes_version_id')->nullable();
            $table->dateTime('effective_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_reference', 100)->nullable();
            $table->text('reason')->nullable();
            $table->boolean('is_current')->default(true);
            // The assessment id only appears on its authoritative row; nullable values preserve all history.
            $table->uuid('current_key')->nullable()->unique();
            $table->timestamps();
            $table->unique(['assessment_record_id', 'version_no'], 'arv_record_version_unique');
            $table->index(['assessment_record_id', 'is_current'], 'arv_record_current_idx');
        });

        Schema::table('assessment_result_versions', function (Blueprint $table): void {
            $table->foreign('supersedes_version_id')->references('id')->on('assessment_result_versions')->restrictOnDelete();
        });

        Schema::create('assessment_appeal_reasons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name_en'); $table->string('name_am')->nullable();
            $table->boolean('is_active')->default(true); $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('assessment_appeals', function (Blueprint $table): void {
            $table->uuid('id')->primary(); $table->string('case_number', 64)->unique();
            $table->foreignUuid('assessment_record_id')->constrained('assessment_records')->restrictOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('assessment_cycle_id')->nullable()->constrained('assessment_cycles')->restrictOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('original_result_version_id')->constrained('assessment_result_versions')->restrictOnDelete();
            $table->foreignUuid('appeal_reason_id')->nullable()->constrained('assessment_appeal_reasons')->restrictOnDelete();
            $table->text('employee_statement'); $table->text('requested_remedy')->nullable();
            $table->string('status', 32)->default('submitted'); $table->dateTime('submitted_at'); $table->dateTime('due_at')->nullable();
            $table->foreignUuid('assigned_committee_id')->nullable()->constrained('grievance_committees')->restrictOnDelete();
            $table->string('decision_status', 40)->nullable(); $table->text('decision_summary')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete(); $table->dateTime('decided_at')->nullable(); $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->index(['assessment_cycle_id', 'organization_id', 'status'], 'aa_cycle_org_status_idx');
            $table->index(['employee_id', 'status'], 'aa_employee_status_idx');
        });

        Schema::create('assessment_appeal_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary(); $table->foreignUuid('appeal_id')->constrained('assessment_appeals')->cascadeOnDelete();
            $table->string('disk', 40); $table->string('path'); $table->string('original_name'); $table->string('mime', 120); $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete(); $table->timestamps();
        });

        Schema::create('assessment_moderation_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary(); $table->foreignUuid('assessment_cycle_id')->constrained('assessment_cycles')->restrictOnDelete();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->string('name'); $table->string('scope_type', 32); $table->string('status', 32)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete(); $table->dateTime('started_at')->nullable(); $table->dateTime('completed_at')->nullable(); $table->timestamps();
        });

        Schema::create('assessment_moderation_items', function (Blueprint $table): void {
            $table->uuid('id')->primary(); $table->foreignUuid('moderation_session_id')->constrained('assessment_moderation_sessions')->cascadeOnDelete();
            $table->foreignUuid('assessment_record_id')->constrained('assessment_records')->restrictOnDelete(); $table->foreignUuid('current_result_version_id')->constrained('assessment_result_versions')->restrictOnDelete();
            $table->string('flag_reason', 80); $table->string('review_status', 32)->default('pending'); $table->text('review_note')->nullable(); $table->string('recommended_action', 40)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete(); $table->dateTime('reviewed_at')->nullable(); $table->timestamps();
            $table->unique(['moderation_session_id', 'assessment_record_id'], 'ami_session_record_unique');
        });

        Schema::create('assessment_result_corrections', function (Blueprint $table): void {
            $table->uuid('id')->primary(); $table->foreignUuid('assessment_record_id')->constrained('assessment_records')->restrictOnDelete();
            $table->foreignUuid('current_result_version_id')->constrained('assessment_result_versions')->restrictOnDelete(); $table->string('correction_type', 40); $table->text('reason'); $table->json('requested_change')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete(); $table->dateTime('requested_at'); $table->string('status', 32)->default('requested');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete(); $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('implemented_result_version_id')->nullable()->constrained('assessment_result_versions')->restrictOnDelete(); $table->timestamps();
            $table->index(['status', 'assessment_record_id'], 'arc_status_record_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_result_corrections'); Schema::dropIfExists('assessment_moderation_items'); Schema::dropIfExists('assessment_moderation_sessions');
        Schema::dropIfExists('assessment_appeal_evidence'); Schema::dropIfExists('assessment_appeals'); Schema::dropIfExists('assessment_appeal_reasons'); Schema::dropIfExists('assessment_result_versions');
    }
};
