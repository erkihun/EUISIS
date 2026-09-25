<?php

declare(strict_types=1);

use App\Enums\DailyActivityStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily Activity Register (ዕለታዊ የሥራ እንቅስቃሴ).
 *
 * One header per employee per work date, holding a snapshot of the
 * organization / unit / position that applied ON THAT DATE, so a later
 * transfer never rewrites where earlier work was done. Items hang off the
 * header; missing days are calculated, never pre-created.
 *
 * Activity is a work record, not attendance and not a performance score.
 * The EPMS columns on items are nullable hooks with no foreign keys yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_activity_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();

            // Work context snapshot for activity_date.
            $table->foreignUuid('employee_assignment_id')->nullable()->constrained('employee_assignments')->nullOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->foreignUuid('position_id')->nullable()->constrained('positions')->nullOnDelete();

            // Gregorian ISO date. The Ethiopian reading is display-only.
            $table->date('activity_date');
            $table->string('status', 32)->default(DailyActivityStatus::Draft->value);

            $table->timestamp('first_submitted_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('submission_count')->default(0);

            $table->boolean('is_late')->default(false);
            $table->text('late_reason')->nullable();

            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_comment')->nullable();

            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reopen_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The concurrency guard: two racing saves can never create two headers.
            $table->unique(['employee_id', 'activity_date'], 'dal_employee_date_unique');
            $table->index(['activity_date', 'status'], 'dal_date_status_idx');
            $table->index(['organization_id', 'activity_date'], 'dal_org_date_idx');
            $table->index(['organization_unit_id', 'activity_date'], 'dal_unit_date_idx');
            $table->index(['reviewed_by', 'status'], 'dal_reviewer_status_idx');
            $table->index(['status', 'submitted_at'], 'dal_status_submitted_idx');
        });

        Schema::create('daily_activity_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('daily_activity_log_id')->constrained('daily_activity_logs')->cascadeOnDelete();

            $table->string('activity_category', 32)->nullable();
            // The related task: a service registered for the employee's position.
            $table->foreignUuid('position_service_id')->nullable()->constrained('position_services')->nullOnDelete();
            // EPMS integration hooks. No foreign keys: EPMS does not exist yet.
            $table->uuid('performance_activity_id')->nullable();
            $table->uuid('kpi_id')->nullable();

            $table->string('title', 255);
            // Nullable so a half-written draft can be saved; submission requires it.
            $table->text('description')->nullable();
            $table->text('output_result')->nullable();
            $table->string('progress_status', 32);

            $table->time('started_at')->nullable();
            $table->time('ended_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->decimal('quantity', 12, 2)->nullable();
            $table->string('unit_of_measure', 64)->nullable();

            $table->text('challenge_issue')->nullable();
            $table->text('next_action')->nullable();
            // Optional reviewer note pinned to this item. Not a chat thread.
            $table->text('reviewer_note')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('daily_activity_log_id', 'dai_log_idx');
            $table->index('position_service_id', 'dai_position_service_idx');
            $table->index('performance_activity_id', 'dai_performance_activity_idx');
            $table->index('activity_category', 'dai_category_idx');
        });

        Schema::create('daily_activity_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('daily_activity_log_id')->constrained('daily_activity_logs')->cascadeOnDelete();
            $table->foreignUuid('daily_activity_item_id')->nullable()->constrained('daily_activity_items')->nullOnDelete();
            $table->string('original_name', 255);
            $table->string('file_path', 512);
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('file_size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('daily_activity_log_id', 'daa_log_idx');
        });

        /*
         * Workflow history. Each submission stores a snapshot of the items as
         * they were sent, so correcting a returned log never erases what was
         * originally submitted or what the reviewer saw.
         */
        Schema::create('daily_activity_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('daily_activity_log_id')->constrained('daily_activity_logs')->cascadeOnDelete();
            $table->string('action', 32);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['daily_activity_log_id', 'created_at'], 'dah_log_created_idx');
        });

        /*
         * Who reviews whom. The structure has no reliable manager link
         * (positions and units carry no supervisor), so authority is
         * configured explicitly instead of being guessed from job titles.
         *
         * A row grants its reviewer the employees of one organization, one
         * unit (optionally with its sub-units), or one named employee.
         */
        Schema::create('daily_activity_reviewer_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('reviewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->cascadeOnDelete();
            $table->boolean('include_sub_units')->default(true);
            $table->foreignUuid('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['reviewer_user_id', 'is_active'], 'dara_reviewer_active_idx');
            $table->index(['organization_id', 'organization_unit_id'], 'dara_org_unit_idx');
        });

        /*
         * One row per reminder actually sent. The unique key makes the
         * scheduler idempotent: re-running it never messages twice.
         */
        Schema::create('daily_activity_reminders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('activity_date');
            $table->string('reminder_type', 32);
            $table->timestamp('sent_at');

            $table->unique(['employee_id', 'activity_date', 'reminder_type'], 'dar_employee_date_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_activity_reminders');
        Schema::dropIfExists('daily_activity_reviewer_assignments');
        Schema::dropIfExists('daily_activity_histories');
        Schema::dropIfExists('daily_activity_attachments');
        Schema::dropIfExists('daily_activity_items');
        Schema::dropIfExists('daily_activity_logs');
    }
};
