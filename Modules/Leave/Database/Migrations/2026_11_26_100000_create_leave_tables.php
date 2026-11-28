<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee leave (ADR 0039). Every table belongs to Modules/Leave.
 *
 * Ids of employees, organizations, units, positions and users are references
 * to the Core's tables, held without foreign keys, as the other modules do.
 * A balance is never stored: it is the sum of leave_ledger_entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_types')) {
            Schema::create('leave_types', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('code', 40)->unique('leave_types_code_uq');
                $table->string('name_en', 150);
                $table->string('name_am', 150);
                $table->string('category', 30);
                // working: working days of the platform calendar; calendar: every day.
                $table->string('day_unit', 20)->default('working');
                // full, none, or by_period (sick leave: full pay, then half pay).
                $table->string('pay_class', 20)->default('full');
                $table->boolean('uses_balance')->default(false);
                $table->boolean('allows_half_day')->default(false);
                $table->boolean('requires_supervisor_approval')->default(true);
                $table->boolean('requires_document')->default(false);
                $table->boolean('employee_can_request')->default(true);
                $table->string('gender', 10)->nullable();
                $table->boolean('is_active')->default(true);
                $table->boolean('is_system')->default(false);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('leave_rule_sets')) {
            Schema::create('leave_rule_sets', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('name', 200);
                $table->string('legal_reference', 500)->nullable();
                $table->date('effective_from');
                $table->date('effective_to')->nullable();
                // draft: being prepared; active: applied to dates inside its period; retired: kept for old requests.
                $table->string('status', 20)->default('draft');
                // Whether the figures were checked against the legal text by the institution.
                $table->boolean('is_confirmed')->default(false);
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->index(['status', 'effective_from'], 'leave_rule_sets_status_from_ix');
            });
        }

        if (! Schema::hasTable('leave_rules')) {
            Schema::create('leave_rules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('leave_rule_set_id');
                $table->uuid('leave_type_id');
                // Yearly grant for types that use a balance.
                $table->decimal('base_days', 6, 1)->nullable();
                $table->decimal('increment_per_service_year', 4, 1)->nullable();
                $table->decimal('max_days', 6, 1)->nullable();
                $table->unsignedSmallInteger('waiting_months')->default(0);
                // none: unused days end with the budget year; automatic: usable for carry_over_years more
                // years; authorized: only when the head of the institution postpones them.
                $table->string('carry_over_mode', 20)->default('none');
                $table->unsignedSmallInteger('carry_over_years')->default(0);
                // Limits for types without a balance.
                $table->decimal('max_days_per_request', 6, 1)->nullable();
                $table->decimal('max_days_per_year', 6, 1)->nullable();
                // Type-specific figures (sick-leave windows and pay periods, maternity periods).
                $table->json('settings')->nullable();
                $table->timestamps();
                $table->unique(['leave_rule_set_id', 'leave_type_id'], 'leave_rules_set_type_uq');
                $table->foreign('leave_rule_set_id', 'leave_rules_set_fk')->references('id')->on('leave_rule_sets')->cascadeOnDelete();
                $table->foreign('leave_type_id', 'leave_rules_type_fk')->references('id')->on('leave_types')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('leave_requests')) {
            Schema::create('leave_requests', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('reference_number', 40)->unique('leave_requests_reference_uq');
                $table->uuid('employee_id');
                $table->unsignedBigInteger('requester_user_id')->nullable();
                // Placement of the employee on the day of the request (never rewritten by a later transfer).
                $table->uuid('organization_id')->nullable();
                $table->uuid('organization_unit_id')->nullable();
                $table->uuid('position_id')->nullable();
                $table->uuid('leave_type_id');
                $table->uuid('leave_rule_set_id')->nullable();
                $table->unsignedSmallInteger('budget_year');
                $table->date('starts_on');
                $table->date('ends_on');
                $table->boolean('is_half_day')->default(false);
                $table->string('half_day_part', 20)->nullable();
                $table->decimal('days_counted', 6, 1);
                // Sick leave: how many of the counted days are at full and at half pay.
                $table->json('pay_breakdown')->nullable();
                $table->text('reason')->nullable();
                $table->string('contact_during_leave', 255)->nullable();
                $table->date('expected_delivery_on')->nullable();
                $table->date('actual_delivery_on')->nullable();
                $table->string('status', 30);
                $table->boolean('registered_by_hr')->default(false);
                $table->unsignedBigInteger('supervisor_user_id')->nullable();
                $table->string('supervisor_basis', 40)->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->unsignedBigInteger('supervisor_decided_by')->nullable();
                $table->timestamp('supervisor_decided_at')->nullable();
                $table->text('supervisor_comment')->nullable();
                $table->unsignedBigInteger('hr_decided_by')->nullable();
                $table->timestamp('hr_decided_at')->nullable();
                $table->text('hr_comment')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancel_reason')->nullable();
                // Set when an approved leave is cut short; the days after it went back to the balance.
                $table->date('ended_early_on')->nullable();
                $table->timestamps();
                $table->index(['employee_id', 'status'], 'leave_requests_employee_status_ix');
                $table->index(['organization_id', 'status', 'starts_on'], 'leave_requests_org_status_ix');
                $table->index(['supervisor_user_id', 'status'], 'leave_requests_supervisor_ix');
                $table->index(['starts_on', 'ends_on'], 'leave_requests_period_ix');
                $table->foreign('leave_type_id', 'leave_requests_type_fk')->references('id')->on('leave_types')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('leave_request_days')) {
            Schema::create('leave_request_days', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('leave_request_id');
                $table->uuid('employee_id');
                $table->date('leave_date');
                $table->decimal('portion', 2, 1)->default(1);
                $table->boolean('is_full_day')->default(true);
                // Counted against the balance or limit (a rest day inside a working-day leave is not).
                $table->boolean('is_counted')->default(true);
                // Mirrors the request so EmployeeLeaveProvider reads one table.
                $table->boolean('is_approved')->default(false);
                $table->unique(['leave_request_id', 'leave_date'], 'leave_request_days_request_date_uq');
                $table->index(['employee_id', 'leave_date', 'is_approved'], 'leave_request_days_employee_date_ix');
                $table->foreign('leave_request_id', 'leave_request_days_request_fk')->references('id')->on('leave_requests')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('leave_request_events')) {
            Schema::create('leave_request_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('leave_request_id');
                $table->string('action', 40);
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('actor_name', 255)->nullable();
                $table->text('comment')->nullable();
                $table->json('data')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['leave_request_id', 'created_at'], 'leave_request_events_request_ix');
                $table->foreign('leave_request_id', 'leave_request_events_request_fk')->references('id')->on('leave_requests')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('leave_ledger_entries')) {
            Schema::create('leave_ledger_entries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('employee_id');
                $table->uuid('leave_type_id');
                // The budget year whose entitlement the days belong to.
                $table->unsignedSmallInteger('entitlement_year');
                $table->string('entry_type', 30);
                // Positive adds days to the balance, negative takes them.
                $table->decimal('days', 7, 1);
                // Postponement: the last budget year in which this year's days may still be used.
                $table->unsignedSmallInteger('usable_until_year')->nullable();
                $table->uuid('leave_request_id')->nullable();
                $table->uuid('leave_rule_set_id')->nullable();
                $table->uuid('organization_id')->nullable();
                $table->text('note')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                // One yearly grant per employee, type and year, however often the grant runs.
                $table->string('unique_key', 150)->nullable()->unique('leave_ledger_unique_key_uq');
                $table->timestamp('created_at')->nullable();
                $table->index(['employee_id', 'leave_type_id', 'entitlement_year'], 'leave_ledger_bucket_ix');
                $table->index('leave_request_id', 'leave_ledger_request_ix');
                $table->foreign('leave_type_id', 'leave_ledger_type_fk')->references('id')->on('leave_types')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('leave_documents')) {
            Schema::create('leave_documents', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('leave_request_id');
                $table->string('kind', 40)->default('evidence');
                $table->string('original_name', 255);
                $table->string('disk', 40)->default('local');
                $table->string('path', 500);
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->timestamps();
                $table->index('leave_request_id', 'leave_documents_request_ix');
                $table->foreign('leave_request_id', 'leave_documents_request_fk')->references('id')->on('leave_requests')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['leave_documents', 'leave_ledger_entries', 'leave_request_events', 'leave_request_days', 'leave_requests', 'leave_rules', 'leave_rule_sets', 'leave_types'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
