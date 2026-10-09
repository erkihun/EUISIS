<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_work_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('name_en', 160);
            $table->string('name_am', 160)->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->boolean('requires_location')->default(false);
            $table->boolean('requires_destination_org')->default(false);
            $table->boolean('requires_attachment')->default(false);
            $table->boolean('requires_completion_note')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('field_work_number_sequences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
        });
        Schema::create('field_work_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference_number', 64)->unique();
            $table->foreignUuid('requester_employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('requester_assignment_id')->nullable()->constrained('employee_assignments')->nullOnDelete();
            // Assignment context at creation; transfers never rewrite it.
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->foreignUuid('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->string('organization_name_snapshot', 255)->nullable();
            $table->string('organization_unit_name_snapshot', 255)->nullable();
            $table->string('position_name_snapshot', 255)->nullable();
            $table->foreignUuid('field_work_type_id')->constrained('field_work_types')->restrictOnDelete();
            $table->string('destination_type', 32);
            $table->foreignUuid('destination_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignUuid('destination_organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->string('external_organization_name', 255)->nullable();
            $table->string('external_contact_person', 160)->nullable();
            $table->string('external_contact_phone', 64)->nullable();
            $table->string('destination_location', 255)->nullable();
            $table->text('destination_address')->nullable();
            $table->text('purpose');
            $table->text('activity_description')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('expected_return_at');
            $table->timestamp('actual_departure_at')->nullable();
            $table->timestamp('actual_return_at')->nullable();
            $table->boolean('is_full_day')->default(false);
            $table->boolean('is_multi_day')->default(false);
            $table->string('status', 40)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUuid('approved_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->foreignUuid('returned_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->foreignUuid('rejected_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignUuid('cancelled_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignUuid('completed_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['requester_employee_id', 'starts_at'], 'fwr_requester_starts_idx');
            $table->index(['organization_id', 'status', 'starts_at'], 'fwr_org_status_starts_idx');
            $table->index(['status', 'expected_return_at'], 'fwr_status_return_idx');
            $table->index(['destination_type', 'destination_organization_id'], 'fwr_destination_idx');
            $table->index(['approved_by_employee_id', 'status'], 'fwr_approver_status_idx');
        });
        Schema::create('field_work_participants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('field_work_request_id')->constrained('field_work_requests')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('employee_assignment_id')->nullable()->constrained('employee_assignments')->nullOnDelete();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->foreignUuid('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->string('participant_role', 64)->default('participant');
            $table->boolean('is_primary_requester')->default(false);
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->unique(['field_work_request_id', 'employee_id'], 'fwp_request_employee_unique');
            $table->index('employee_id', 'fwp_employee_idx');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('field_work_participants');
        Schema::dropIfExists('field_work_requests');
        Schema::dropIfExists('field_work_number_sequences');
        Schema::dropIfExists('field_work_types');
    }
};
