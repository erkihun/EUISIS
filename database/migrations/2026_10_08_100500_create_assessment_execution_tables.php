<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assessment Execution & Evaluator Workspace (docs/assessment-execution.md).
 *
 * Extends the existing assessment records / responses (no second engine):
 * evaluator assignments get a type, status, deadline and optimistic-lock
 * version; criterion answers, evidence, submitted revisions and evaluator
 * changes get their own tables. Additive and resumable on MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $add = function (string $table, string $column, Closure $define): void {
            if (! Schema::hasColumn($table, $column)) {
                Schema::table($table, $define);
            }
        };

        // ── Evaluator assignments (one row per evaluator of a record) ──────────
        $add('assessment_responses', 'evaluator_type', fn (Blueprint $t) => $t->string('evaluator_type', 30)->default('peer')->after('evaluator_id'));
        $add('assessment_responses', 'evaluator_scheme_id', fn (Blueprint $t) => $t->uuid('evaluator_scheme_id')->nullable()->after('evaluator_type'));
        // not_started | in_progress | submitted | returned | conflict_declared | cancelled
        $add('assessment_responses', 'status', fn (Blueprint $t) => $t->string('status', 20)->default('not_started')->after('evaluator_scheme_id'));
        $add('assessment_responses', 'is_anonymous', fn (Blueprint $t) => $t->boolean('is_anonymous')->default(false));
        $add('assessment_responses', 'due_at', fn (Blueprint $t) => $t->date('due_at')->nullable());
        $add('assessment_responses', 'lock_version', fn (Blueprint $t) => $t->unsignedInteger('lock_version')->default(0));
        $add('assessment_responses', 'started_at', fn (Blueprint $t) => $t->dateTime('started_at')->nullable());
        $add('assessment_responses', 'last_saved_at', fn (Blueprint $t) => $t->dateTime('last_saved_at')->nullable());
        $add('assessment_responses', 'submission_count', fn (Blueprint $t) => $t->unsignedSmallInteger('submission_count')->default(0));
        $add('assessment_responses', 'submitted_late', fn (Blueprint $t) => $t->boolean('submitted_late')->default(false));
        $add('assessment_responses', 'late_reason', fn (Blueprint $t) => $t->text('late_reason')->nullable());
        $add('assessment_responses', 'returned_at', fn (Blueprint $t) => $t->dateTime('returned_at')->nullable());
        $add('assessment_responses', 'returned_by', fn (Blueprint $t) => $t->unsignedBigInteger('returned_by')->nullable());
        $add('assessment_responses', 'return_reason', fn (Blueprint $t) => $t->text('return_reason')->nullable());
        $add('assessment_responses', 'conflict_declared_at', fn (Blueprint $t) => $t->dateTime('conflict_declared_at')->nullable());
        $add('assessment_responses', 'conflict_reason', fn (Blueprint $t) => $t->text('conflict_reason')->nullable());
        $add('assessment_responses', 'cancelled_at', fn (Blueprint $t) => $t->dateTime('cancelled_at')->nullable());
        $add('assessment_responses', 'last_reminded_at', fn (Blueprint $t) => $t->dateTime('last_reminded_at')->nullable());
        if (! Schema::hasIndex('assessment_responses', 'aresp_evaluator_status_idx')) {
            Schema::table('assessment_responses', fn (Blueprint $t) => $t->index(['evaluator_id', 'status'], 'aresp_evaluator_status_idx'));
        }
        if (! Schema::hasIndex('assessment_responses', 'aresp_status_due_idx')) {
            Schema::table('assessment_responses', fn (Blueprint $t) => $t->index(['status', 'due_at'], 'aresp_status_due_idx'));
        }
        // Existing rows: a submitted response is SUBMITTED; the rest have not started.
        DB::table('assessment_responses')->whereNotNull('submitted_at')->where('status', 'not_started')->update(['status' => 'submitted', 'submission_count' => 1]);

        // ── Records: finalization, band snapshot, breakdown ────────────────────
        $add('assessment_records', 'organization_unit_id', fn (Blueprint $t) => $t->uuid('organization_unit_id')->nullable()->after('organization_id'));
        if (! Schema::hasIndex('assessment_records', 'ar_cycle_unit_idx')) {
            Schema::table('assessment_records', fn (Blueprint $t) => $t->index(['assessment_cycle_id', 'organization_unit_id'], 'ar_cycle_unit_idx'));
        }
        $add('assessment_records', 'score_breakdown', fn (Blueprint $t) => $t->json('score_breakdown')->nullable());
        $add('assessment_records', 'finalized_at', fn (Blueprint $t) => $t->dateTime('finalized_at')->nullable());
        $add('assessment_records', 'finalized_by', fn (Blueprint $t) => $t->unsignedBigInteger('finalized_by')->nullable());
        $add('assessment_records', 'band_policy_id', fn (Blueprint $t) => $t->uuid('band_policy_id')->nullable());
        $add('assessment_records', 'band_code', fn (Blueprint $t) => $t->string('band_code', 40)->nullable());
        $add('assessment_records', 'band_label_en', fn (Blueprint $t) => $t->string('band_label_en')->nullable());
        $add('assessment_records', 'band_label_am', fn (Blueprint $t) => $t->string('band_label_am')->nullable());
        $add('assessment_records', 'acknowledgement_comment', fn (Blueprint $t) => $t->text('acknowledgement_comment')->nullable());
        $add('assessment_records', 'reopened_at', fn (Blueprint $t) => $t->dateTime('reopened_at')->nullable());
        $add('assessment_records', 'reopen_reason', fn (Blueprint $t) => $t->text('reopen_reason')->nullable());
        if (! Schema::hasIndex('assessment_records', 'ar_status_idx')) {
            Schema::table('assessment_records', fn (Blueprint $t) => $t->index(['status', 'organization_id'], 'ar_status_idx'));
        }
        if (! Schema::hasIndex('assessment_records', 'ar_form_version_idx')) {
            Schema::table('assessment_records', fn (Blueprint $t) => $t->index('form_version_id', 'ar_form_version_idx'));
        }
        // Generated assignments may not have a named reviewer yet; any authorized reviewer in scope finalizes.
        Schema::table('assessment_records', fn (Blueprint $t) => $t->unsignedBigInteger('reviewer_id')->nullable()->change());

        // ── Cycle execution settings (NULL = NEEDS_DECISION) ───────────────────
        $add('assessment_cycles', 'evaluation_due_date', fn (Blueprint $t) => $t->date('evaluation_due_date')->nullable());
        // block | allow_with_reason | allow_flagged
        $add('assessment_cycles', 'late_submission_policy', fn (Blueprint $t) => $t->string('late_submission_policy', 20)->nullable());
        $add('assessment_cycles', 'assignment_generation_status', fn (Blueprint $t) => $t->string('assignment_generation_status', 20)->nullable());
        $add('assessment_cycles', 'assignments_generated_at', fn (Blueprint $t) => $t->dateTime('assignments_generated_at')->nullable());

        // ── Form version: may evaluators see option scores? ────────────────────
        $add('assessment_form_versions', 'show_option_scores', fn (Blueprint $t) => $t->boolean('show_option_scores')->default(true));

        // ── Criterion answers ──────────────────────────────────────────────────
        if (! Schema::hasTable('assessment_response_items')) {
            Schema::create('assessment_response_items', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('response_id')->constrained('assessment_responses', 'id', 'ari_response_fk')->restrictOnDelete();
                $table->foreignUuid('criterion_id')->constrained('assessment_criteria', 'id', 'ari_criterion_fk')->restrictOnDelete();
                $table->foreignUuid('rating_option_id')->nullable()->constrained('assessment_rating_options', 'id', 'ari_option_fk')->restrictOnDelete();
                // Resolved by the server from the option at submission; never sent by the browser.
                $table->decimal('score_snapshot', 12, 4)->nullable();
                $table->text('comment')->nullable();
                $table->timestamps();
                $table->unique(['response_id', 'criterion_id'], 'ari_response_criterion_unique');
            });
        }

        if (! Schema::hasTable('assessment_response_evidence')) {
            Schema::create('assessment_response_evidence', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('response_id')->constrained('assessment_responses', 'id', 'are_response_fk')->restrictOnDelete();
                $table->foreignUuid('criterion_id')->constrained('assessment_criteria', 'id', 'are_criterion_fk')->restrictOnDelete();
                $table->string('disk', 40);
                $table->string('path');
                $table->string('original_name');
                $table->string('mime', 120);
                $table->unsignedInteger('size');
                $table->foreignId('uploaded_by')->constrained('users', 'id', 'are_uploaded_by_fk')->restrictOnDelete();
                $table->timestamps();
                $table->index(['response_id', 'criterion_id'], 'are_response_criterion_idx');
            });
        }

        // Previous submitted versions, kept when a response is returned for correction.
        if (! Schema::hasTable('assessment_response_revisions')) {
            Schema::create('assessment_response_revisions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('response_id')->constrained('assessment_responses', 'id', 'arr_response_fk')->restrictOnDelete();
                $table->unsignedSmallInteger('revision_no');
                $table->json('items');
                $table->json('score_snapshot')->nullable();
                $table->dateTime('submitted_at')->nullable();
                $table->dateTime('returned_at');
                $table->foreignId('returned_by')->constrained('users', 'id', 'arr_returned_by_fk')->restrictOnDelete();
                $table->text('return_reason');
                $table->dateTime('created_at');
                $table->unique(['response_id', 'revision_no'], 'arr_response_revision_unique');
            });
        }

        if (! Schema::hasTable('assessment_evaluator_changes')) {
            Schema::create('assessment_evaluator_changes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('assessment_record_id')->constrained('assessment_records', 'id', 'aec_record_fk')->restrictOnDelete();
                $table->foreignUuid('from_response_id')->nullable()->constrained('assessment_responses', 'id', 'aec_from_fk')->restrictOnDelete();
                $table->foreignUuid('to_response_id')->nullable()->constrained('assessment_responses', 'id', 'aec_to_fk')->restrictOnDelete();
                $table->unsignedBigInteger('from_evaluator_id')->nullable();
                $table->unsignedBigInteger('to_evaluator_id')->nullable();
                $table->string('evaluator_type', 30);
                // transferred | unavailable | left_employment | conflict | incorrect | initial
                $table->string('reason_code', 30);
                $table->text('reason')->nullable();
                $table->foreignId('changed_by')->constrained('users', 'id', 'aec_changed_by_fk')->restrictOnDelete();
                $table->dateTime('created_at');
                $table->index('assessment_record_id', 'aec_record_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_evaluator_changes');
        Schema::dropIfExists('assessment_response_revisions');
        Schema::dropIfExists('assessment_response_evidence');
        Schema::dropIfExists('assessment_response_items');
        foreach (['assessment_responses' => ['evaluator_type', 'evaluator_scheme_id', 'status', 'is_anonymous', 'due_at', 'lock_version', 'started_at', 'last_saved_at', 'submission_count', 'submitted_late', 'late_reason', 'returned_at', 'returned_by', 'return_reason', 'conflict_declared_at', 'conflict_reason', 'cancelled_at', 'last_reminded_at'],
            'assessment_records' => ['organization_unit_id', 'score_breakdown', 'finalized_at', 'finalized_by', 'band_policy_id', 'band_code', 'band_label_en', 'band_label_am', 'acknowledgement_comment', 'reopened_at', 'reopen_reason'],
            'assessment_cycles' => ['evaluation_due_date', 'late_submission_policy', 'assignment_generation_status', 'assignments_generated_at'],
            'assessment_form_versions' => ['show_option_scores']] as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($table, $columns): void {
                if ($table === 'assessment_responses') {
                    $t->dropIndex('aresp_evaluator_status_idx');
                    $t->dropIndex('aresp_status_due_idx');
                }
                if ($table === 'assessment_records') {
                    $t->dropIndex('ar_status_idx');
                    $t->dropIndex('ar_form_version_idx');
                    $t->dropIndex('ar_cycle_unit_idx');
                }
                $t->dropColumn($columns);
            });
        }
    }
};
