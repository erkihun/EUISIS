<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Field Work Management (የመስክ ሥራ አስተዳደር) — docs/field-work-management.md.
 *
 * Official work performed away from the employee's duty station. It is NOT a
 * transfer, leave, training, mission or remote work, and it never touches
 * employee_assignments: the requester's (and each participant's) placement
 * is SNAPSHOTTED here when the request is created, so a later transfer or
 * restructure cannot rewrite where the work was organised from.
 *
 * GPS is event-based, never continuous: each check-in / check-out is one
 * immutable row in field_work_location_events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_work_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name_en', 150);
            $table->string('name_am', 150)->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            // Deactivated types stay for history; they just cannot be chosen.
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'fwt_active_sort_idx');
        });

        Schema::create('field_work_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference_number', 32)->unique();

            // Requester, resolved server-side from the signed-in user.
            $table->foreignUuid('requester_employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('requester_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Placement snapshot when the request was created / last corrected.
            $table->foreignUuid('employee_assignment_id')->nullable()->constrained('employee_assignments')->nullOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->foreignUuid('position_id')->nullable()->constrained('positions')->nullOnDelete();
            // Display names as they were, so renames do not rewrite history.
            $table->json('context_snapshot')->nullable();

            $table->foreignUuid('field_work_type_id')->constrained('field_work_types')->restrictOnDelete();
            $table->text('purpose');
            $table->text('activity_description')->nullable();

            // Destination.
            $table->string('destination_type', 32);
            $table->foreignUuid('destination_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('destination_organization_unit_id')->nullable()->constrained('organization_units')->restrictOnDelete();
            // External organizations are stored as text; never auto-registered as master data.
            $table->string('external_organization_name', 255)->nullable();
            $table->string('site_name', 255)->nullable();
            $table->string('destination_address', 500)->nullable();
            $table->string('contact_person', 150)->nullable();
            $table->string('contact_phone', 32)->nullable();
            // Optional expected point + radius for the server-side geofence.
            $table->decimal('expected_latitude', 10, 7)->nullable();
            $table->decimal('expected_longitude', 10, 7)->nullable();
            $table->unsignedInteger('geofence_radius_m')->nullable();

            // Schedule (app-timezone timestamps; Ethiopian reading is display-only).
            $table->timestamp('starts_at');
            $table->timestamp('expected_return_at');
            $table->string('schedule_type', 16);
            $table->boolean('is_team')->default(false);

            $table->string('status', 40);

            // Approver resolved from explicit line-manager assignments.
            $table->foreignId('supervisor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('supervisor_resolution', 32)->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->unsignedSmallInteger('submission_count')->default(0);
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_reason')->nullable();

            $table->timestamp('actual_start_at')->nullable();
            $table->timestamp('actual_return_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('completion_note')->nullable();
            $table->text('outcome')->nullable();
            $table->boolean('follow_up_required')->default(false);
            $table->text('follow_up_note')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();

            $table->timestamps();

            $table->index(['requester_employee_id', 'starts_at'], 'fwr_requester_start_idx');
            $table->index(['organization_id', 'status', 'starts_at'], 'fwr_org_status_start_idx');
            $table->index(['organization_unit_id', 'starts_at'], 'fwr_unit_start_idx');
            $table->index(['supervisor_user_id', 'status'], 'fwr_supervisor_status_idx');
            $table->index(['status', 'expected_return_at'], 'fwr_status_return_idx');
            $table->index(['starts_at', 'expected_return_at'], 'fwr_window_idx');
            $table->index('field_work_type_id', 'fwr_type_idx');
            $table->index(['destination_type', 'starts_at'], 'fwr_dest_type_idx');
            $table->index('destination_organization_id', 'fwr_dest_org_idx');
            $table->index('employee_assignment_id', 'fwr_assignment_idx');
            $table->index('decided_at', 'fwr_decided_idx');
        });

        /*
         * Everyone who goes, the requester included (role = lead). Relational,
         * never a JSON list of ids, so "who was in the field" is queryable and
         * each person checks in and out for themselves.
         */
        Schema::create('field_work_participants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('field_work_request_id')->constrained('field_work_requests')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('role', 16);
            // Placement snapshot of THIS participant.
            $table->foreignUuid('employee_assignment_id')->nullable()->constrained('employee_assignments')->nullOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->foreignUuid('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamps();

            $table->unique(['field_work_request_id', 'employee_id'], 'fwp_request_employee_unique');
            $table->index(['employee_id', 'field_work_request_id'], 'fwp_employee_request_idx');
            $table->index('organization_id', 'fwp_org_idx');
        });

        /*
         * Immutable GPS captures. One row per lifecycle event; the check-in
         * row is never updated by the check-out. The unique key makes a
         * duplicate check-in / check-out impossible even under a race.
         */
        Schema::create('field_work_location_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('field_work_request_id')->constrained('field_work_requests')->restrictOnDelete();
            $table->foreignUuid('field_work_participant_id')->constrained('field_work_participants')->restrictOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('event_type', 32);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy_m', 9, 2)->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('received_at');
            $table->decimal('distance_m', 12, 2)->nullable();
            $table->string('validation_status', 32);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['field_work_participant_id', 'event_type'], 'fwle_participant_event_unique');
            $table->index(['field_work_request_id', 'event_type'], 'fwle_request_event_idx');
            $table->index(['employee_id', 'captured_at'], 'fwle_employee_captured_idx');
            $table->index(['event_type', 'captured_at'], 'fwle_event_captured_idx');
        });

        Schema::create('field_work_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('field_work_request_id')->constrained('field_work_requests')->cascadeOnDelete();
            $table->string('action', 32);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['field_work_request_id', 'created_at'], 'fwh_request_created_idx');
        });

        // Defence in depth beyond Form Request validation (PostgreSQL only;
        // SQLite test databases cannot add constraints after creation).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE field_work_requests ADD CONSTRAINT fwr_return_after_start CHECK (expected_return_at > starts_at)');
            DB::statement('ALTER TABLE field_work_requests ADD CONSTRAINT fwr_expected_lat CHECK (expected_latitude IS NULL OR expected_latitude BETWEEN -90 AND 90)');
            DB::statement('ALTER TABLE field_work_requests ADD CONSTRAINT fwr_expected_lng CHECK (expected_longitude IS NULL OR expected_longitude BETWEEN -180 AND 180)');
            DB::statement('ALTER TABLE field_work_location_events ADD CONSTRAINT fwle_lat CHECK (latitude BETWEEN -90 AND 90)');
            DB::statement('ALTER TABLE field_work_location_events ADD CONSTRAINT fwle_lng CHECK (longitude BETWEEN -180 AND 180)');
            DB::statement('ALTER TABLE field_work_location_events ADD CONSTRAINT fwle_accuracy CHECK (accuracy_m IS NULL OR accuracy_m >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('field_work_histories');
        Schema::dropIfExists('field_work_location_events');
        Schema::dropIfExists('field_work_participants');
        Schema::dropIfExists('field_work_requests');
        Schema::dropIfExists('field_work_types');
    }
};
