<?php

declare(strict_types=1);

// migration-safety: reviewed — two new tables, nothing existing is changed.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0034: an institution's existing work unit designated to handle public
 * Tip-offs or complaints (each type has its own unit), and its named members, each registered with a
 * signature image (path + SHA-256, who recorded it and when). Nothing is
 * created in the organization structure; terms are ended, never deleted.
 * Guarded so a retry on MySQL can resume.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('public_case_handling_units')) {
            Schema::create('public_case_handling_units', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('organization_id')->constrained('organizations');
                $table->foreignUuid('organization_unit_id')->constrained('organization_units');
                $table->string('case_type', 20); // suggestion (Tip-offs), complaint
                $table->string('status', 20)->default('active'); // active, inactive
                $table->text('description_en')->nullable();
                $table->text('description_am')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['organization_unit_id', 'case_type'], 'pchu_unit_type_unique');
                $table->index(['organization_id', 'case_type', 'status'], 'pchu_org_type_status_idx');
            });
        }

        if (! Schema::hasTable('public_case_handling_unit_members')) {
            Schema::create('public_case_handling_unit_members', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('handling_unit_id')->constrained('public_case_handling_units');
                $table->foreignUuid('employee_id')->constrained('employees');
                $table->string('responsibility', 20); // head, officer
                $table->date('effective_from');
                $table->date('effective_to')->nullable();
                $table->string('status', 20)->default('active'); // active, inactive
                $table->string('appointment_reference')->nullable();
                $table->unsignedBigInteger('appointed_by')->nullable();
                $table->text('end_reason')->nullable();
                $table->string('signature_path');
                $table->string('signature_sha256', 64);
                $table->dateTime('signature_recorded_at');
                $table->unsignedBigInteger('signature_recorded_by')->nullable();
                $table->timestamps();

                $table->index(['handling_unit_id', 'status'], 'pchum_unit_status_idx');
                $table->index('employee_id', 'pchum_employee_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('public_case_handling_unit_members');
        Schema::dropIfExists('public_case_handling_units');
    }
};
