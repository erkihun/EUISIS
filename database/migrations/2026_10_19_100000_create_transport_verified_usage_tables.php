<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Transport as verified usage (docs/transport/architecture.md).
 *
 * Adds what the evidence model needs around the existing transport tables:
 * ordered stops and directional schedules on a route, a trip lifecycle with
 * actual times and vehicle substitutions, registered scanner devices with
 * their own revocable credential, eligibility grants, versioned contracts,
 * provider invoices, settlement statements with adjustments, operational
 * exceptions and optional vehicle positions. Boarding transactions gain the
 * columns that tie each one to a trip, vehicle, device, stop and direction.
 *
 * Additive only: no column or row is removed. Legacy trip statuses are
 * mapped onto the lifecycle (scheduled → planned, departed → in_progress).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->routeStops();
        $this->schedulesAndCalendar();
        $this->contracts();
        $this->devices();
        $this->eligibilities();
        $this->trips();
        $this->passes();
        $this->vehicles();
        $this->transactions();
        $this->exceptions();
        $this->positions();
        $this->invoices();
        $this->settlements();
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_settlement_adjustments');
        Schema::dropIfExists('transport_invoices');
        Schema::dropIfExists('transport_trip_positions');
        Schema::dropIfExists('transport_exceptions');
        Schema::dropIfExists('transport_trip_vehicle_changes');
        Schema::dropIfExists('transport_service_calendar_days');

        $this->dropColumns('transport_transactions', [
            'transport_vehicle_id', 'transport_device_id', 'transport_route_stop_id', 'direction', 'verification_method', 'boarding_leg',
            'eligibility_snapshot', 'sync_state', 'offline_recorded_at', 'voided_at', 'voided_by_user_id', 'void_reason',
            'transport_settlement_id', 'manual_reason', 'recorded_by_user_id',
        ]);
        $this->dropColumns('transport_passes', ['boarding_stop_id', 'evening_stop_id', 'transport_eligibility_id', 'suspended_reason', 'suspended_at']);
        $this->dropColumns('transport_vehicles', ['standing_capacity', 'inspection_expires_at', 'insurance_expires_at', 'permit_expires_at', 'maintenance_until']);
        $this->dropColumns('transport_trips', [
            'direction', 'transport_schedule_id', 'planned_departure_at', 'planned_arrival_at', 'actual_started_at', 'actual_ended_at',
            'started_by_provider_user_id', 'completed_by_provider_user_id', 'cancelled_at', 'cancellation_reason', 'cancelled_by_provider_user_id',
            'cancelled_by_user_id', 'is_extra', 'approved_by', 'expected_riders', 'distance_km', 'completion_notes',
        ]);
        $this->dropColumns('transport_settlements', [
            'settlement_number', 'provider_id', 'transport_contract_id', 'transport_invoice_id', 'organization_id', 'period_start', 'period_end',
            'status', 'contract_snapshot', 'basis', 'verified_boardings', 'unique_riders', 'employee_days', 'completed_trips', 'cancelled_trips',
            'currency_code', 'calculated_amount', 'claimed_amount', 'adjustment_total', 'approved_amount', 'computed_by', 'computed_at',
            'reviewed_by', 'reviewed_at', 'approved_by', 'approved_at', 'rejected_reason', 'payment_reference', 'payment_marked_at',
            'payment_marked_by', 'notes', 'policy_gaps',
        ]);

        Schema::dropIfExists('transport_eligibilities');
        Schema::dropIfExists('transport_devices');
        Schema::dropIfExists('transport_contracts');
        Schema::dropIfExists('transport_schedules');
        Schema::dropIfExists('transport_route_stops');
    }

    private function routeStops(): void
    {
        if (Schema::hasTable('transport_route_stops')) {
            return;
        }

        Schema::create('transport_route_stops', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('transport_route_id')->constrained('transport_routes')->cascadeOnDelete();
            $table->string('stop_code', 40);
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('sequence');
            $table->boolean('pickup_allowed')->default(true);
            $table->boolean('dropoff_allowed')->default(true);
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['transport_route_id', 'stop_code'], 'trs_route_stop_code_unique');
            $table->index(['transport_route_id', 'sequence'], 'trs_route_sequence_idx');
        });
    }

    private function schedulesAndCalendar(): void
    {
        if (! Schema::hasTable('transport_schedules')) {
            Schema::create('transport_schedules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('provider_id')->constrained('providers')->cascadeOnDelete();
                $table->foreignUuid('transport_route_id')->constrained('transport_routes')->cascadeOnDelete();
                // inbound = home → work (morning), outbound = work → home (evening).
                $table->string('direction', 16);
                // ISO weekdays the schedule runs on (1 = Monday … 7 = Sunday).
                $table->json('service_days');
                $table->time('planned_departure');
                $table->time('planned_arrival')->nullable();
                $table->boolean('skips_public_holidays')->default(true);
                $table->foreignUuid('default_vehicle_id')->nullable()->constrained('transport_vehicles')->nullOnDelete();
                $table->foreignUuid('default_driver_id')->nullable()->constrained('transport_drivers')->nullOnDelete();
                $table->date('effective_from');
                $table->date('effective_to')->nullable();
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['transport_route_id', 'direction', 'is_active'], 'tsch_route_direction_idx');
                $table->index(['provider_id', 'is_active'], 'tsch_provider_idx');
            });
        }

        if (! Schema::hasTable('transport_service_calendar_days')) {
            Schema::create('transport_service_calendar_days', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                // Null provider/route: the whole city. A route row overrides a provider row.
                $table->foreignUuid('provider_id')->nullable()->constrained('providers')->cascadeOnDelete();
                $table->foreignUuid('transport_route_id')->nullable()->constrained('transport_routes')->cascadeOnDelete();
                $table->date('calendar_date');
                // operating: runs although the calendar says not; suspended: no service that day.
                $table->string('kind', 20);
                $table->string('name_en')->nullable();
                $table->string('name_am')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['calendar_date', 'kind'], 'tscd_date_idx');
            });
        }
    }

    private function contracts(): void
    {
        if (Schema::hasTable('transport_contracts')) {
            return;
        }

        Schema::create('transport_contracts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('contract_reference', 60);
            // Null organization: a citywide contract.
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignUuid('provider_id')->constrained('providers')->cascadeOnDelete();
            $table->unsignedSmallInteger('version_no')->default(1);
            // Self-reference: its foreign key is added below, once the table and
            // its primary key exist. Inline, PostgreSQL rejects it (42830: no
            // unique constraint on the referenced table yet).
            $table->uuid('supersedes_contract_id')->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('currency_code', 3)->default('ETB');
            $table->string('settlement_model', 40);
            $table->string('settlement_period', 16)->default('monthly');
            // Approved rates as decimal strings, keyed per settlement model. Never computed here.
            $table->json('terms')->nullable();
            $table->text('service_coverage')->nullable();
            $table->string('agreement_path')->nullable();
            $table->string('terms_reference')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['contract_reference', 'version_no'], 'tc_reference_version_unique');
            $table->index(['provider_id', 'status'], 'tc_provider_status_idx');
            $table->index('organization_id', 'tc_organization_idx');
        });

        Schema::table('transport_contracts', function (Blueprint $table): void {
            $table->foreign('supersedes_contract_id', 'tc_supersedes_contract_fk')->references('id')->on('transport_contracts')->nullOnDelete();
        });
    }

    private function devices(): void
    {
        if (Schema::hasTable('transport_devices')) {
            return;
        }

        Schema::create('transport_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->constrained('providers')->cascadeOnDelete();
            // A device bound to a vehicle may only board that vehicle's trips.
            $table->foreignUuid('transport_vehicle_id')->nullable()->constrained('transport_vehicles')->nullOnDelete();
            $table->string('device_code', 40);
            $table->string('name');
            $table->string('status', 20)->default('active');
            // SHA-256 of the bearer credential; the credential itself is shown once at registration.
            $table->string('credential_hash', 64)->unique();
            $table->string('credential_prefix', 16);
            $table->string('platform', 40)->nullable();
            $table->string('software_version', 40)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revocation_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['provider_id', 'device_code'], 'td_provider_code_unique');
            $table->index(['provider_id', 'status'], 'td_provider_status_idx');
        });
    }

    private function eligibilities(): void
    {
        if (Schema::hasTable('transport_eligibilities')) {
            return;
        }

        Schema::create('transport_eligibilities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // employee: one person; organization: an office (self) or an office and its subtree.
            $table->string('subject_type', 20);
            $table->foreignUuid('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->string('scope_type', 16)->default('self');
            // Null provider: any transport provider serving the employee.
            $table->foreignUuid('provider_id')->nullable()->constrained('providers')->nullOnDelete();
            $table->foreignUuid('transport_contract_id')->nullable()->constrained('transport_contracts')->nullOnDelete();
            $table->string('status', 16)->default('active');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('authorization_reference')->nullable();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status'], 'te_employee_status_idx');
            $table->index(['organization_id', 'status'], 'te_organization_status_idx');
        });
    }

    private function trips(): void
    {
        if (! Schema::hasTable('transport_trips')) {
            return;
        }

        Schema::table('transport_trips', function (Blueprint $table): void {
            if (! Schema::hasColumn('transport_trips', 'direction')) {
                $table->string('direction', 16)->nullable()->after('trip_number');
            }
            if (! Schema::hasColumn('transport_trips', 'transport_schedule_id')) {
                $table->foreignUuid('transport_schedule_id')->nullable()->after('direction')->constrained('transport_schedules')->nullOnDelete();
            }
            foreach (['planned_departure_at', 'planned_arrival_at', 'actual_started_at', 'actual_ended_at', 'cancelled_at'] as $column) {
                if (! Schema::hasColumn('transport_trips', $column)) {
                    $table->timestamp($column)->nullable();
                }
            }
            foreach (['started_by_provider_user_id', 'completed_by_provider_user_id', 'cancelled_by_provider_user_id'] as $column) {
                if (! Schema::hasColumn('transport_trips', $column)) {
                    $table->foreignUuid($column)->nullable()->constrained('provider_users')->nullOnDelete();
                }
            }
            if (! Schema::hasColumn('transport_trips', 'cancelled_by_user_id')) {
                $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('transport_trips', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable();
            }
            if (! Schema::hasColumn('transport_trips', 'is_extra')) {
                $table->boolean('is_extra')->default(false);
            }
            if (! Schema::hasColumn('transport_trips', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('transport_trips', 'expected_riders')) {
                $table->unsignedInteger('expected_riders')->nullable();
            }
            if (! Schema::hasColumn('transport_trips', 'distance_km')) {
                $table->decimal('distance_km', 8, 2)->nullable();
            }
            if (! Schema::hasColumn('transport_trips', 'completion_notes')) {
                $table->text('completion_notes')->nullable();
            }
        });

        $indexes = collect(Schema::getIndexes('transport_trips'))->pluck('name')->all();
        Schema::table('transport_trips', function (Blueprint $table) use ($indexes): void {
            if (! in_array('tt_route_date_direction_idx', $indexes, true)) {
                $table->index(['transport_route_id', 'trip_date', 'direction'], 'tt_route_date_direction_idx');
            }
            if (! in_array('tt_vehicle_date_idx', $indexes, true)) {
                $table->index(['transport_vehicle_id', 'trip_date'], 'tt_vehicle_date_idx');
            }
            if (! in_array('tt_date_status_idx', $indexes, true)) {
                $table->index(['trip_date', 'status'], 'tt_date_status_idx');
            }
        });

        // Legacy statuses onto the lifecycle; the data itself is untouched.
        DB::table('transport_trips')->where('status', 'scheduled')->update(['status' => 'planned']);
        DB::table('transport_trips')->where('status', 'departed')->update(['status' => 'in_progress']);

        if (! Schema::hasTable('transport_trip_vehicle_changes')) {
            Schema::create('transport_trip_vehicle_changes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('transport_trip_id')->constrained('transport_trips')->cascadeOnDelete();
                $table->foreignUuid('from_vehicle_id')->nullable()->constrained('transport_vehicles')->nullOnDelete();
                $table->foreignUuid('to_vehicle_id')->nullable()->constrained('transport_vehicles')->nullOnDelete();
                $table->foreignUuid('from_driver_id')->nullable()->constrained('transport_drivers')->nullOnDelete();
                $table->foreignUuid('to_driver_id')->nullable()->constrained('transport_drivers')->nullOnDelete();
                $table->text('reason');
                $table->timestamp('changed_at');
                // Named: the generated name exceeds MySQL's 64-character limit.
                $table->foreignUuid('changed_by_provider_user_id')->nullable()->constrained('provider_users', 'id', 'ttvc_changed_by_provider_user_fk')->nullOnDelete();
                $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('transport_trip_id', 'ttvc_trip_idx');
            });
        }
    }

    private function passes(): void
    {
        if (! Schema::hasTable('transport_passes')) {
            return;
        }

        // A pass is the employee's route assignment: default route and the two stops.
        Schema::table('transport_passes', function (Blueprint $table): void {
            if (! Schema::hasColumn('transport_passes', 'boarding_stop_id')) {
                $table->foreignUuid('boarding_stop_id')->nullable()->constrained('transport_route_stops')->nullOnDelete();
            }
            if (! Schema::hasColumn('transport_passes', 'evening_stop_id')) {
                $table->foreignUuid('evening_stop_id')->nullable()->constrained('transport_route_stops')->nullOnDelete();
            }
            if (! Schema::hasColumn('transport_passes', 'transport_eligibility_id')) {
                $table->foreignUuid('transport_eligibility_id')->nullable()->constrained('transport_eligibilities')->nullOnDelete();
            }
            if (! Schema::hasColumn('transport_passes', 'suspended_reason')) {
                $table->string('suspended_reason', 80)->nullable();
            }
            if (! Schema::hasColumn('transport_passes', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable();
            }
        });
    }

    private function vehicles(): void
    {
        if (! Schema::hasTable('transport_vehicles')) {
            return;
        }

        Schema::table('transport_vehicles', function (Blueprint $table): void {
            if (! Schema::hasColumn('transport_vehicles', 'standing_capacity')) {
                $table->unsignedInteger('standing_capacity')->nullable()->after('capacity');
            }
            // Compliance dates are optional until the city decides which it tracks
            // (NEEDS_POLICY_DECISION_VEHICLE_COMPLIANCE_FIELDS).
            foreach (['inspection_expires_at', 'insurance_expires_at', 'permit_expires_at', 'maintenance_until'] as $column) {
                if (! Schema::hasColumn('transport_vehicles', $column)) {
                    $table->date($column)->nullable();
                }
            }
        });
    }

    private function transactions(): void
    {
        if (! Schema::hasTable('transport_transactions')) {
            return;
        }

        Schema::table('transport_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('transport_transactions', 'transport_vehicle_id')) {
                $table->foreignUuid('transport_vehicle_id')->nullable()->constrained('transport_vehicles')->nullOnDelete();
            }
            if (! Schema::hasColumn('transport_transactions', 'transport_device_id')) {
                $table->foreignUuid('transport_device_id')->nullable()->constrained('transport_devices')->nullOnDelete();
            }
            if (! Schema::hasColumn('transport_transactions', 'transport_route_stop_id')) {
                $table->foreignUuid('transport_route_stop_id')->nullable()->constrained('transport_route_stops')->nullOnDelete();
            }
            if (! Schema::hasColumn('transport_transactions', 'direction')) {
                $table->string('direction', 16)->nullable();
            }
            if (! Schema::hasColumn('transport_transactions', 'verification_method')) {
                $table->string('verification_method', 20)->default('qr');
            }
            if (! Schema::hasColumn('transport_transactions', 'boarding_leg')) {
                // "<date>:<direction>" — one accepted boarding per leg when the policy says so.
                $table->string('boarding_leg', 40)->nullable();
            }
            if (! Schema::hasColumn('transport_transactions', 'eligibility_snapshot')) {
                $table->json('eligibility_snapshot')->nullable();
            }
            if (! Schema::hasColumn('transport_transactions', 'sync_state')) {
                $table->string('sync_state', 24)->default('online');
            }
            if (! Schema::hasColumn('transport_transactions', 'offline_recorded_at')) {
                $table->timestamp('offline_recorded_at')->nullable();
            }
            if (! Schema::hasColumn('transport_transactions', 'voided_at')) {
                $table->timestamp('voided_at')->nullable();
            }
            if (! Schema::hasColumn('transport_transactions', 'voided_by_user_id')) {
                $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('transport_transactions', 'void_reason')) {
                $table->text('void_reason')->nullable();
            }
            if (! Schema::hasColumn('transport_transactions', 'transport_settlement_id')) {
                $table->uuid('transport_settlement_id')->nullable();
            }
            if (! Schema::hasColumn('transport_transactions', 'manual_reason')) {
                $table->text('manual_reason')->nullable();
            }
            if (! Schema::hasColumn('transport_transactions', 'recorded_by_user_id')) {
                $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
        });

        $indexes = collect(Schema::getIndexes('transport_transactions'))->pluck('name')->all();
        Schema::table('transport_transactions', function (Blueprint $table) use ($indexes): void {
            foreach ([
                'ttx_trip_status_idx' => ['transport_trip_id', 'status'],
                'ttx_vehicle_date_idx' => ['transport_vehicle_id', 'transaction_date'],
                'ttx_device_scanned_idx' => ['transport_device_id', 'scanned_at'],
                'ttx_date_direction_status_idx' => ['transaction_date', 'direction', 'status'],
                'ttx_settlement_idx' => ['transport_settlement_id'],
                'ttx_sync_state_idx' => ['sync_state', 'transaction_date'],
            ] as $name => $columns) {
                if (! in_array($name, $indexes, true)) {
                    $table->index($columns, $name);
                }
            }
        });
    }

    private function exceptions(): void
    {
        if (Schema::hasTable('transport_exceptions')) {
            return;
        }

        Schema::create('transport_exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->constrained('providers')->cascadeOnDelete();
            $table->foreignUuid('transport_trip_id')->nullable()->constrained('transport_trips')->nullOnDelete();
            $table->foreignUuid('transport_transaction_id')->nullable()->constrained('transport_transactions')->nullOnDelete();
            $table->foreignUuid('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignUuid('transport_vehicle_id')->nullable()->constrained('transport_vehicles')->nullOnDelete();
            $table->foreignUuid('transport_device_id')->nullable()->constrained('transport_devices')->nullOnDelete();
            $table->string('type', 40);
            $table->string('severity', 10)->default('warning');
            $table->string('status', 16)->default('open');
            $table->json('detail')->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'status', 'occurred_at'], 'tex_provider_status_idx');
            $table->index(['type', 'occurred_at'], 'tex_type_idx');
            $table->index('transport_trip_id', 'tex_trip_idx');
        });
    }

    private function positions(): void
    {
        if (Schema::hasTable('transport_trip_positions')) {
            return;
        }

        // Vehicle positions only (never an employee's). Retention: NEEDS_POLICY_DECISION_TRANSPORT_RETENTION.
        Schema::create('transport_trip_positions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('transport_trip_id')->constrained('transport_trips')->cascadeOnDelete();
            $table->foreignUuid('transport_device_id')->nullable()->constrained('transport_devices')->nullOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('speed_kmh', 6, 2)->nullable();
            $table->unsignedSmallInteger('heading')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['transport_trip_id', 'recorded_at'], 'ttp_trip_time_idx');
        });
    }

    private function invoices(): void
    {
        if (Schema::hasTable('transport_invoices')) {
            return;
        }

        Schema::create('transport_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('invoice_number', 60);
            $table->foreignUuid('provider_id')->constrained('providers')->cascadeOnDelete();
            $table->foreignUuid('transport_contract_id')->nullable()->constrained('transport_contracts')->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('claimed_boardings')->nullable();
            $table->unsignedInteger('claimed_trips')->nullable();
            $table->unsignedInteger('claimed_employee_days')->nullable();
            $table->decimal('claimed_amount', 14, 2);
            $table->string('currency_code', 3)->default('ETB');
            $table->string('status', 20)->default('draft');
            $table->string('attachment_path')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('submitted_by_provider_user_id')->nullable()->constrained('provider_users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->text('returned_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->uuid('transport_settlement_id')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'invoice_number'], 'tinv_provider_number_unique');
            $table->index(['provider_id', 'status'], 'tinv_provider_status_idx');
            $table->index(['period_start', 'period_end'], 'tinv_period_idx');
        });
    }

    private function settlements(): void
    {
        // The domain foundation created transport_settlements as an empty shell
        // (id, provider_settlement_id); it becomes the settlement statement.
        if (! Schema::hasTable('transport_settlements')) {
            Schema::create('transport_settlements', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('provider_settlement_id')->nullable()->index();
                $table->timestamps();
            });
        }

        Schema::table('transport_settlements', function (Blueprint $table): void {
            $add = fn (string $column): bool => ! Schema::hasColumn('transport_settlements', $column);

            if ($add('settlement_number')) {
                $table->string('settlement_number', 40)->nullable()->unique();
            }
            if ($add('provider_id')) {
                $table->foreignUuid('provider_id')->nullable()->constrained('providers')->cascadeOnDelete();
            }
            if ($add('transport_contract_id')) {
                $table->foreignUuid('transport_contract_id')->nullable()->constrained('transport_contracts')->nullOnDelete();
            }
            if ($add('transport_invoice_id')) {
                $table->foreignUuid('transport_invoice_id')->nullable()->constrained('transport_invoices')->nullOnDelete();
            }
            if ($add('organization_id')) {
                $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            }
            if ($add('period_start')) {
                $table->date('period_start')->nullable();
            }
            if ($add('period_end')) {
                $table->date('period_end')->nullable();
            }
            if ($add('status')) {
                $table->string('status', 20)->default('draft');
            }
            if ($add('contract_snapshot')) {
                $table->json('contract_snapshot')->nullable();
            }
            if ($add('basis')) {
                $table->json('basis')->nullable();
            }
            foreach (['verified_boardings', 'unique_riders', 'employee_days', 'completed_trips', 'cancelled_trips'] as $column) {
                if ($add($column)) {
                    $table->unsignedInteger($column)->default(0);
                }
            }
            if ($add('currency_code')) {
                $table->string('currency_code', 3)->default('ETB');
            }
            foreach (['calculated_amount', 'claimed_amount', 'approved_amount'] as $column) {
                if ($add($column)) {
                    $table->decimal($column, 14, 2)->nullable();
                }
            }
            if ($add('adjustment_total')) {
                $table->decimal('adjustment_total', 14, 2)->default(0);
            }
            foreach (['computed_by', 'reviewed_by', 'approved_by', 'payment_marked_by'] as $column) {
                if ($add($column)) {
                    $table->foreignId($column)->nullable()->constrained('users')->nullOnDelete();
                }
            }
            foreach (['computed_at', 'reviewed_at', 'approved_at', 'payment_marked_at'] as $column) {
                if ($add($column)) {
                    $table->timestamp($column)->nullable();
                }
            }
            if ($add('rejected_reason')) {
                $table->text('rejected_reason')->nullable();
            }
            if ($add('payment_reference')) {
                $table->string('payment_reference', 120)->nullable();
            }
            if ($add('notes')) {
                $table->text('notes')->nullable();
            }
            if ($add('policy_gaps')) {
                $table->json('policy_gaps')->nullable();
            }
        });

        $indexes = collect(Schema::getIndexes('transport_settlements'))->pluck('name')->all();
        Schema::table('transport_settlements', function (Blueprint $table) use ($indexes): void {
            if (! in_array('tset_provider_period_idx', $indexes, true)) {
                $table->index(['provider_id', 'period_start', 'period_end'], 'tset_provider_period_idx');
            }
            if (! in_array('tset_status_idx', $indexes, true)) {
                $table->index('status', 'tset_status_idx');
            }
        });

        if (! Schema::hasTable('transport_settlement_adjustments')) {
            Schema::create('transport_settlement_adjustments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('transport_settlement_id')->constrained('transport_settlements')->cascadeOnDelete();
                // provider_dispute: raised in the portal; organization_adjustment: raised by the reviewer.
                $table->string('origin', 24);
                $table->string('kind', 24)->default('financial');
                $table->string('dispute_type', 40)->nullable();
                $table->decimal('amount', 14, 2)->default(0);
                $table->integer('quantity')->nullable();
                $table->text('reason');
                $table->string('evidence_path')->nullable();
                $table->string('status', 16)->default('pending');
                // Named: the generated name exceeds MySQL's 64-character limit.
                $table->foreignUuid('requested_by_provider_user_id')->nullable()->constrained('provider_users', 'id', 'tsa_requested_by_provider_user_fk')->nullOnDelete();
                $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('decided_at')->nullable();
                $table->text('decision_notes')->nullable();
                $table->timestamps();

                $table->index(['transport_settlement_id', 'status'], 'tsa_settlement_status_idx');
            });
        }
    }

    /** @param  list<string>  $columns */
    private function dropColumns(string $tableName, array $columns): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $present = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn($tableName, $column)));
        if ($present === []) {
            return;
        }

        // PostgreSQL drops a column's foreign key with the column; SQLite rebuilds the table.
        Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($present));
    }
};
