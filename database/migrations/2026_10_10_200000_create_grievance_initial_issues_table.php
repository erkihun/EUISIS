<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grievance_initial_issues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->nullable()->constrained('grievances')->nullOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('employee_assignment_id')->nullable()->constrained('employee_assignments')->restrictOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->foreignUuid('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignUuid('responsible_officer_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('responsible_officer_name_snapshot')->nullable();
            $table->string('issue_subject');
            $table->text('issue_description');
            $table->text('requested_resolution')->nullable();
            $table->timestamp('written_issue_raised_at');
            $table->timestamp('discussion_date')->nullable();
            $table->text('discussion_notes')->nullable();
            $table->text('officer_response')->nullable();
            $table->string('outcome')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('supporting_document_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index('grievance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grievance_initial_issues');
    }
};
