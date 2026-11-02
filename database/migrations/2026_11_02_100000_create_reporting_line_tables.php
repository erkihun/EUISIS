<?php

declare(strict_types=1);

use App\Support\Database\HeadPositionIndexes;
use App\Support\Database\SchemaResume;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reporting hierarchy and supervisor resolution (ADR 0025).
 *
 *   acting_appointments    — an employee temporarily filling a position; the
 *                            reporting line resolves to them while it runs.
 *   supervisor_delegations — a supervisor lending named actions to a colleague
 *                            for a period; the reporting line does not move.
 *   field_work_requests    — records which rule named the supervisor and, when
 *                            a delegate decided, whom they decided for.
 *   positions              — one head per unit and per organization, enforced
 *                            by the database where the data already allows it.
 *
 * Everything is additive: no column changes meaning, nothing is backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaResume::create('acting_appointments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('position_id')->constrained('positions')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();

            $table->index(['position_id', 'status', 'starts_on'], 'acting_appointments_position_window_idx');
            $table->index(['employee_id', 'status'], 'acting_appointments_employee_status_idx');
        });

        SchemaResume::create('supervisor_delegations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('delegator_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUuid('delegate_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->json('actions');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();

            $table->index(['delegate_employee_id', 'status', 'starts_on'], 'supervisor_delegations_delegate_window_idx');
            $table->index(['delegator_employee_id', 'status'], 'supervisor_delegations_delegator_status_idx');
        });

        if (Schema::hasTable('field_work_requests')) {
            SchemaResume::table('field_work_requests', function (Blueprint $table): void {
                if (! Schema::hasColumn('field_work_requests', 'supervisor_basis')) {
                    $table->string('supervisor_basis', 40)->nullable()->after('supervisor_resolution');
                }
                if (! Schema::hasColumn('field_work_requests', 'decided_for_user_id')) {
                    $table->foreignId('decided_for_user_id')->nullable()->after('decided_by')->constrained('users', indexName: 'field_work_requests_decided_for_user_fk')->nullOnDelete();
                }
            });
        }

        // Left out, not failed, when legacy data already has two heads: see HeadPositionIndexes.
        HeadPositionIndexes::ensure();
    }

    public function down(): void
    {
        HeadPositionIndexes::drop();

        if (Schema::hasTable('field_work_requests')) {
            Schema::table('field_work_requests', function (Blueprint $table): void {
                if (Schema::hasColumn('field_work_requests', 'decided_for_user_id')) {
                    $table->dropConstrainedForeignId('decided_for_user_id');
                }
                if (Schema::hasColumn('field_work_requests', 'supervisor_basis')) {
                    $table->dropColumn('supervisor_basis');
                }
            });
        }

        Schema::dropIfExists('supervisor_delegations');
        Schema::dropIfExists('acting_appointments');
    }
};
