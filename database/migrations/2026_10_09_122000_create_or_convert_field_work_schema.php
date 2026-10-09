<?php

declare(strict_types=1);

use App\Enums\FieldWorkScheduleType;
use App\Enums\FieldWorkStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Field Work Management (የመስክ ሥራ አስተዳደር) — docs/field-work-management.md.
 *
 * Builds the Field Work schema, CONVERTING the earlier schema where it exists.
 *
 * An earlier implementation (2026_10_09_120000_create_field_work_management_tables)
 * created field_work_types / _number_sequences / _requests / _participants.
 * Its next migration always failed, so no database ever got further than
 * those four tables, but they may exist and hold rows. Every database
 * therefore takes the same path here:
 *
 *   field_work_types         kept in place; sort_order / created_by /
 *                            updated_by added (the older requires_* columns
 *                            stay, unused, so no data is dropped)
 *   field_work_requests,     rows read, legacy tables dropped, the current
 *   field_work_participants  tables created, rows re-inserted with their ids,
 *                            references and history preserved (see convert*)
 *   field_work_number_sequences  dropped (references are random, unique-keyed)
 *
 * It never touches employee_assignments: the placement is snapshotted.
 * GPS is event-based, never continuous: one immutable row per check-in /
 * check-out in field_work_location_events.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('field_work_requests') && Schema::hasColumn('field_work_requests', 'schedule_type')) {
            return; // Already the current schema.
        }

        $this->upgradeTypes();

        [$legacyRequests, $legacyParticipants] = $this->readLegacy();

        Schema::dropIfExists('field_work_participants');
        Schema::dropIfExists('field_work_requests');
        Schema::dropIfExists('field_work_number_sequences');

        $this->createTables();
        $this->convert($legacyRequests, $legacyParticipants);
    }

    public function down(): void
    {
        Schema::dropIfExists('field_work_histories');
        Schema::dropIfExists('field_work_location_events');
        Schema::dropIfExists('field_work_participants');
        Schema::dropIfExists('field_work_requests');

        if (Schema::hasTable('field_work_types')) {
            Schema::table('field_work_types', function (Blueprint $table): void {
                if (Schema::hasColumn('field_work_types', 'created_by')) {
                    $table->dropIndex('fwt_created_by_idx');
                }
                if (Schema::hasColumn('field_work_types', 'sort_order')) {
                    $table->dropIndex('fwt_active_sort_idx');
                }
            });
            Schema::table('field_work_types', function (Blueprint $table): void {
                $table->dropColumn(array_values(array_filter(['created_by', 'updated_by', 'sort_order'], static fn (string $c): bool => Schema::hasColumn('field_work_types', $c))));
            });
        }
    }

    private function upgradeTypes(): void
    {
        if (! Schema::hasTable('field_work_types')) {
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

            return;
        }

        Schema::table('field_work_types', function (Blueprint $table): void {
            if (! Schema::hasColumn('field_work_types', 'sort_order')) {
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->index(['is_active', 'sort_order'], 'fwt_active_sort_idx');
            }
            // Audit columns without a foreign key: adding a constrained column
            // to an existing table makes SQLite rebuild it.
            if (! Schema::hasColumn('field_work_types', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->index('fwt_created_by_idx');
            }
            if (! Schema::hasColumn('field_work_types', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable();
            }
        });
    }

    /** @return array{0: Collection<int, object>, 1: Collection<int, object>} */
    private function readLegacy(): array
    {
        $legacy = Schema::hasTable('field_work_requests') && Schema::hasColumn('field_work_requests', 'requester_assignment_id');

        return $legacy
            ? [DB::table('field_work_requests')->orderBy('created_at')->get(), DB::table('field_work_participants')->get()]
            : [collect(), collect()];
    }

    private function createTables(): void
    {
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
            // GPS policy outcome (System Settings > Field Work GPS): not_required |
            // pending_supervisor_review. A "block" outcome is refused, not stored.
            $table->string('review_state', 32)->default('not_required');
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

    /**
     * @param  Collection<int, object>  $requests
     * @param  Collection<int, object>  $participants
     */
    private function convert(Collection $requests, Collection $participants): void
    {
        if ($requests->isEmpty()) {
            return;
        }

        // Approvers, cancellers and completers were recorded as employees;
        // the current schema records the acting user account.
        $employeeIds = $requests->flatMap(fn (object $r): array => [$r->approved_by_employee_id, $r->returned_by_employee_id, $r->rejected_by_employee_id, $r->cancelled_by_employee_id, $r->completed_by_employee_id])->filter()->unique()->values()->all();
        $userFor = DB::table('users')->whereIn('employee_id', $employeeIds)->orderBy('id')->get(['id', 'employee_id'])->unique('employee_id')->pluck('id', 'employee_id');
        $byRequest = $participants->groupBy('field_work_request_id');
        $statuses = FieldWorkStatus::values();

        foreach ($requests as $r) {
            $status = in_array($r->status, $statuses, true) ? $r->status : FieldWorkStatus::Draft->value;
            [$decidedBy, $decidedAt, $reason] = match ($status) {
                FieldWorkStatus::ReturnedForCorrection->value => [$r->returned_by_employee_id, $r->returned_at, $r->return_reason],
                FieldWorkStatus::Rejected->value => [$r->rejected_by_employee_id, $r->rejected_at, $r->rejection_reason],
                default => [$r->approved_by_employee_id, $r->approved_at, $r->approval_note],
            };
            $members = $byRequest->get($r->id, collect());

            DB::table('field_work_requests')->insert([
                'id' => $r->id,
                'reference_number' => $r->reference_number,
                'requester_employee_id' => $r->requester_employee_id,
                'requester_user_id' => $r->created_by,
                'employee_assignment_id' => $r->requester_assignment_id,
                'organization_id' => $r->organization_id,
                'organization_unit_id' => $r->organization_unit_id,
                'position_id' => $r->position_id,
                'context_snapshot' => json_encode([
                    'organization' => ['name_en' => $r->organization_name_snapshot, 'name_am' => null],
                    'organization_unit' => $r->organization_unit_name_snapshot !== null ? ['name_en' => $r->organization_unit_name_snapshot, 'name_am' => null] : null,
                    'position' => $r->position_name_snapshot !== null ? ['name_en' => $r->position_name_snapshot, 'name_am' => null] : null,
                    'converted_from_legacy_schema' => true,
                ]),
                'field_work_type_id' => $r->field_work_type_id,
                'purpose' => $r->purpose,
                'activity_description' => $r->activity_description,
                'destination_type' => $r->destination_type,
                'destination_organization_id' => $r->destination_organization_id,
                'destination_organization_unit_id' => $r->destination_organization_unit_id,
                'external_organization_name' => $r->external_organization_name,
                'site_name' => $r->destination_location,
                'destination_address' => $r->destination_address,
                'contact_person' => $r->external_contact_person,
                'contact_phone' => $r->external_contact_phone !== null ? Str::limit($r->external_contact_phone, 32, '') : null,
                'starts_at' => $r->starts_at,
                'expected_return_at' => $r->expected_return_at,
                'schedule_type' => ((bool) $r->is_multi_day ? FieldWorkScheduleType::MultiDay : ((bool) $r->is_full_day ? FieldWorkScheduleType::FullDay : FieldWorkScheduleType::PartialDay))->value,
                'is_team' => $members->count() > 1,
                'status' => $status,
                'supervisor_user_id' => $decidedBy !== null ? ($userFor[$decidedBy] ?? null) : null,
                'supervisor_resolution' => null,
                'submitted_at' => $r->submitted_at,
                'submission_count' => $r->submitted_at !== null ? 1 : 0,
                'decided_at' => $decidedAt,
                'decided_by' => $decidedBy !== null ? ($userFor[$decidedBy] ?? null) : null,
                'decision_reason' => $reason,
                'actual_start_at' => $r->actual_departure_at,
                'actual_return_at' => $r->actual_return_at,
                'completed_at' => $r->completed_at,
                'completed_by' => $r->completed_by_employee_id !== null ? ($userFor[$r->completed_by_employee_id] ?? null) : null,
                'completion_note' => $r->completion_note,
                'cancelled_at' => $r->cancelled_at,
                'cancelled_by' => $r->cancelled_by_employee_id !== null ? ($userFor[$r->cancelled_by_employee_id] ?? null) : null,
                'cancel_reason' => $r->cancellation_reason,
                'created_at' => $r->created_at,
                'updated_at' => $r->updated_at,
            ]);

            foreach ($members as $p) {
                DB::table('field_work_participants')->insert([
                    'id' => $p->id,
                    'field_work_request_id' => $r->id,
                    'employee_id' => $p->employee_id,
                    'role' => ((bool) $p->is_primary_requester || $p->employee_id === $r->requester_employee_id) ? 'lead' : 'member',
                    'employee_assignment_id' => $p->employee_assignment_id,
                    'organization_id' => $p->organization_id ?? $r->organization_id,
                    'organization_unit_id' => $p->organization_unit_id,
                    'position_id' => $p->position_id,
                    'created_at' => $p->created_at,
                    'updated_at' => $p->updated_at,
                ]);
            }

            DB::table('field_work_histories')->insert([
                'id' => (string) Str::uuid7(),
                'field_work_request_id' => $r->id,
                'action' => 'updated',
                'from_status' => $status,
                'to_status' => $status,
                'comment' => 'Converted from the earlier Field Work schema (2026_10_09_122000).',
                'created_at' => now(),
            ]);
        }
    }
};
