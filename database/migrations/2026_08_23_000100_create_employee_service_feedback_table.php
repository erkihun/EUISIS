<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client feedback on the service a single employee provided.
 *
 * The organization / unit / position columns are a SNAPSHOT taken at submission
 * time, not a live join through the employee's current assignment. A transfer
 * must not silently rewrite history and move last year's complaint to a new
 * office, so these are copied once and never updated.
 *
 * `service_type_id` points at the existing platform `service_types` catalog —
 * the feedback module deliberately does not maintain a second service list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_service_feedback', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUuid('employee_feedback_token_id')->nullable()
                ->constrained('employee_feedback_tokens')->nullOnDelete();

            // Assignment snapshot — nullable because an employee may be unassigned.
            $table->foreignUuid('organization_id')->nullable()
                ->constrained('organizations')->nullOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()
                ->constrained('organization_units')->nullOnDelete();
            $table->foreignUuid('position_id')->nullable()
                ->constrained('positions')->nullOnDelete();

            $table->foreignUuid('service_type_id')->constrained('service_types')->restrictOnDelete();

            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();

            // Volunteered by the client or left empty; never required to submit.
            $table->string('client_name')->nullable();
            $table->string('client_contact')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->string('status', 16)->default('pending')->index();
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            // Dashboard and report shapes: per-employee and per-org over a date range.
            $table->index(['employee_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['service_type_id', 'rating']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_service_feedback');
    }
};
