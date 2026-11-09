<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0029: an existing organization unit designated as a Grievance Team or
 * Grievance Directorate, and its named members with their Regulation
 * 201/2018 responsibility and signature snapshot. Nothing is created in the
 * organization structure. Guarded so a retry on MySQL can resume.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('grievance_handler_units')) {
            Schema::create('grievance_handler_units', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('organization_id')->constrained('organizations');
                $table->foreignUuid('organization_unit_id')->unique()->constrained('organization_units');
                $table->string('kind', 20); // team, directorate
                $table->string('status', 20)->default('active'); // active, inactive
                $table->text('description_en')->nullable();
                $table->text('description_am')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['organization_id', 'status']);
            });
        }

        if (! Schema::hasTable('grievance_handler_unit_members')) {
            Schema::create('grievance_handler_unit_members', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('handler_unit_id')->constrained('grievance_handler_units');
                $table->foreignUuid('employee_id')->constrained('employees');
                $table->string('responsibility', 20); // head, investigator, secretary
                $table->date('effective_from');
                $table->date('effective_to')->nullable();
                $table->string('status', 20)->default('active'); // active, inactive
                $table->string('appointment_reference')->nullable();
                $table->unsignedBigInteger('appointed_by')->nullable();
                $table->text('end_reason')->nullable();
                $table->string('signature_path')->nullable();
                $table->string('signature_sha256', 64)->nullable();
                $table->dateTime('signature_recorded_at')->nullable();
                $table->unsignedBigInteger('signature_recorded_by')->nullable();
                $table->timestamps();

                $table->index(['handler_unit_id', 'status'], 'grievance_unit_members_unit_status_index');
                $table->index('employee_id', 'grievance_unit_members_employee_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('grievance_handler_unit_members');
        Schema::dropIfExists('grievance_handler_units');
    }
};
