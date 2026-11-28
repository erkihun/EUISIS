<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Work experience (ADR 0040, Modules/WorkExperience/README.md).
 *
 *   work_experience_records          what the office recorded about an employee's service: a line of
 *                                    its own, or the details (salary, corrected title) of one assignment
 *   work_experience_requests         an employee's request for a written certificate, and what became of it
 *   work_experience_office_settings  per institution: who signs, and what the certificate carries
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_experience_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            // The assignment this line gives the details of; null for a line the office wrote itself.
            $table->foreignUuid('employee_assignment_id')->nullable()->unique()->constrained('employee_assignments')->nullOnDelete();
            // internal: service in the employee's own institution; external: service elsewhere, taken from evidence.
            $table->string('kind', 16)->default('internal');
            $table->string('employer_name', 255)->nullable();
            $table->string('position_title', 255);
            $table->string('unit_name', 255)->nullable();
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->decimal('monthly_salary', 12, 2)->nullable();
            $table->string('note', 1000)->nullable();
            $table->string('evidence_disk', 32)->nullable();
            $table->string('evidence_path', 500)->nullable();
            $table->string('evidence_name', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'started_on']);
        });

        Schema::create('work_experience_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference_number', 32)->unique();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('requester_user_id')->nullable()->constrained('users')->nullOnDelete();
            // True when the office entered the request for an employee who has left and has no account.
            $table->boolean('registered_by_office')->default(false);
            $table->string('addressee', 255)->nullable();
            $table->string('purpose', 500);
            $table->string('language', 2)->default('am');
            // submitted → accepted → sent, or submitted → returned / cancelled
            $table->string('status', 16)->default('submitted');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_comment', 1000)->nullable();
            // The certificate: numbered per institution and Ethiopian year, frozen as it was accepted.
            $table->unsignedSmallInteger('certificate_year')->nullable();
            $table->unsignedInteger('certificate_sequence')->nullable();
            $table->string('certificate_number', 48)->nullable();
            $table->string('verification_code', 16)->nullable()->unique();
            $table->json('certificate')->nullable();
            $table->unsignedInteger('print_count')->default(0);
            $table->timestamp('printed_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->string('delivery_note', 500)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['employee_id', 'created_at']);
            $table->unique(['organization_id', 'certificate_year', 'certificate_sequence'], 'work_experience_certificate_number_unique');
        });

        Schema::create('work_experience_office_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
            $table->string('signatory_name_am', 255)->nullable();
            $table->string('signatory_name_en', 255)->nullable();
            $table->string('signatory_title_am', 255)->nullable();
            $table->string('signatory_title_en', 255)->nullable();
            $table->boolean('include_salary')->default(true);
            $table->boolean('include_external')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_experience_office_settings');
        Schema::dropIfExists('work_experience_requests');
        Schema::dropIfExists('work_experience_records');
    }
};
