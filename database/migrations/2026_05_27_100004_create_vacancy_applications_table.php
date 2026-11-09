<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancy_applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('application_number')->unique();
            $table->foreignUuid('vacancy_announcement_id')->constrained('vacancy_announcements');
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->foreignUuid('current_organization_id')->constrained('organizations');
            $table->foreignUuid('current_position_id')->nullable()->constrained('positions');
            $table->string('status')->default('submitted')->index();
            $table->dateTime('applied_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->decimal('screening_score', 5, 2)->nullable();
            $table->text('screening_notes')->nullable();
            $table->foreignId('screened_by')->nullable()->constrained('users');
            $table->timestamp('screened_at')->nullable();
            $table->foreignId('shortlisted_by')->nullable()->constrained('users');
            $table->timestamp('shortlisted_at')->nullable();
            $table->foreignId('selected_by')->nullable()->constrained('users');
            $table->timestamp('selected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users');
            $table->timestamp('rejected_at')->nullable();
            $table->foreignUuid('transfer_id')->nullable()->constrained('employee_transfers');
            $table->timestamps();

            $table->unique(['vacancy_announcement_id', 'employee_id']);
            $table->index(['employee_id', 'status']);
            $table->index(['vacancy_announcement_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancy_applications');
    }
};
