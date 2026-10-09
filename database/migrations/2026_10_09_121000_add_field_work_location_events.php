<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_work_requests', function (Blueprint $table): void {
            $table->decimal('destination_latitude', 10, 8)->nullable()->after('destination_address');
            $table->decimal('destination_longitude', 11, 8)->nullable()->after('destination_latitude');
            $table->unsignedInteger('destination_radius_meters')->nullable()->after('destination_longitude');
        });
        Schema::create('field_work_location_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('field_work_request_id')->constrained('field_work_requests')->cascadeOnDelete();
            $table->foreignUuid('field_work_participant_id')->nullable()->constrained('field_work_participants')->nullOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('employee_assignment_id')->nullable()->constrained('employee_assignments')->nullOnDelete();
            $table->string('event_type', 32); $table->decimal('latitude', 10, 8); $table->decimal('longitude', 11, 8);
            $table->decimal('accuracy_meters', 10, 2)->nullable(); $table->decimal('altitude_meters', 10, 2)->nullable(); $table->decimal('heading_degrees', 7, 2)->nullable(); $table->decimal('speed_mps', 10, 2)->nullable();
            // received_at is server-authoritative. captured_at is optional client provenance only.
            $table->timestamp('captured_at')->nullable(); $table->timestamp('received_at'); $table->string('location_source', 32)->default('browser_geolocation'); $table->string('permission_state', 32)->nullable();
            $table->boolean('is_within_expected_area')->nullable(); $table->decimal('distance_from_destination_meters', 14, 2)->nullable(); $table->string('validation_status', 32); $table->string('validation_reason', 255)->nullable();
            $table->uuid('idempotency_key')->unique(); $table->timestamp('created_at')->useCurrent();
            $table->unique(['field_work_request_id', 'field_work_participant_id', 'event_type'], 'fwle_one_event_per_participant_type');
            $table->index(['field_work_request_id', 'captured_at'], 'fwle_request_captured_idx'); $table->index(['employee_id', 'event_type', 'created_at'], 'fwle_employee_event_idx');
        });
    }
    public function down(): void { Schema::dropIfExists('field_work_location_events'); Schema::table('field_work_requests', function (Blueprint $table): void { $table->dropColumn(['destination_latitude', 'destination_longitude', 'destination_radius_meters']); }); }
};
