<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('announcement_id')->constrained('transfer_announcements');
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->foreignUuid('current_assignment_id')->constrained('employee_assignments');
            $table->foreignUuid('releasing_organization_id')->constrained('organizations');
            $table->foreignUuid('receiving_organization_id')->constrained('organizations');

            $table->string('status', 30)->default('submitted')->index();

            // Snapshot of eligibility at submission time
            $table->json('eligibility_snapshot')->nullable();

            // Application notes / cover letter
            $table->text('applicant_notes')->nullable();

            // Screening / selection tracking
            $table->timestamp('selected_at')->nullable();
            $table->foreignId('selected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejected_reason')->nullable();

            $table->timestamp('submitted_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
            $table->index(['announcement_id', 'status']);
            $table->index(['releasing_organization_id', 'status']);
            $table->index(['receiving_organization_id', 'status']);

            // Prevent duplicate active applications for same announcement by same employee
            $table->unique(['announcement_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_applications');
    }
};
