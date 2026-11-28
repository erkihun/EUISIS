<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The yearly leave schedule (ADR 0039, "Leave schedule"): when the budget
 * year begins each employee tells the institution when they mean to take
 * their annual leave. One row per planned period. A plan holds no days and
 * spends no balance; it becomes leave only through a leave request.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_plans')) {
            return;
        }
        Schema::create('leave_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('employee_id');
            // Placement of the employee when the period was planned, for the unit's and the institution's schedule.
            $table->uuid('organization_id')->nullable();
            $table->uuid('organization_unit_id')->nullable();
            $table->uuid('leave_type_id');
            $table->unsignedSmallInteger('budget_year');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('days_counted', 6, 1);
            $table->string('note', 500)->nullable();
            // The request this planned period turned into, once the employee asked for it.
            $table->uuid('leave_request_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'budget_year'], 'leave_plans_employee_year_ix');
            $table->index(['organization_id', 'budget_year', 'starts_on'], 'leave_plans_org_year_ix');
            $table->index(['organization_unit_id', 'budget_year'], 'leave_plans_unit_year_ix');
            $table->foreign('leave_type_id', 'leave_plans_type_fk')->references('id')->on('leave_types')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_plans');
    }
};
