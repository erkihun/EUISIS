<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regulation 201/2018 Art. 33: the formal-filing window and the
 * force-majeure late-filing review, plus the submission-time snapshot of
 * position and immediate supervisor (Art. 32 grievance application).
 * All columns are additive and nullable; no existing data is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grievances', function (Blueprint $table) {
            $table->foreignUuid('initial_issue_id')->nullable()->constrained('grievance_initial_issues')->nullOnDelete();
            $table->foreignUuid('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignUuid('immediate_supervisor_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('filing_clock_started_at')->nullable();
            $table->timestamp('filing_deadline_at')->nullable();
            $table->boolean('is_late_submission')->default(false);
            $table->text('late_submission_reason')->nullable();
            $table->timestamp('cause_started_at')->nullable();
            $table->timestamp('cause_ended_at')->nullable();
            $table->string('late_review_status')->nullable();
            $table->foreignId('late_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('late_reviewed_at')->nullable();
            $table->text('late_decision_reason')->nullable();

            $table->index('filing_deadline_at');
        });
    }

    public function down(): void
    {
        Schema::table('grievances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('initial_issue_id');
            $table->dropConstrainedForeignId('position_id');
            $table->dropConstrainedForeignId('immediate_supervisor_employee_id');
            $table->dropConstrainedForeignId('late_reviewed_by');
            $table->dropColumn([
                'filing_clock_started_at', 'filing_deadline_at', 'is_late_submission',
                'late_submission_reason', 'cause_started_at', 'cause_ended_at',
                'late_review_status', 'late_reviewed_at', 'late_decision_reason',
            ]);
        });
    }
};
