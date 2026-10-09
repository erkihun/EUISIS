<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_work_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('field_work_request_id')->constrained('field_work_requests')->cascadeOnDelete();
            $table->foreignUuid('field_work_participant_id')->constrained('field_work_participants')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('employee_assignment_id')->nullable()->constrained('employee_assignments')->nullOnDelete();
            $table->timestamp('approved_start_at');
            $table->timestamp('approved_end_at');
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            // Context for a future authoritative attendance adapter; not a biometric event.
            $table->timestamp('attendance_from')->nullable();
            $table->timestamp('attendance_to')->nullable();
            $table->string('status', 40)->default('approved_not_checked_in');
            $table->timestamps();
            $table->unique(['field_work_request_id', 'field_work_participant_id'], 'fws_request_participant_unique');
            $table->index(['employee_id', 'status', 'approved_start_at'], 'fws_employee_status_start_idx');
            $table->index(['status', 'approved_end_at'], 'fws_status_end_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_work_sessions');
    }
};
