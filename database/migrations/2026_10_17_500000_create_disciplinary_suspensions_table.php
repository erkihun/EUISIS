<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suspension from work pending a disciplinary case — Regulation 201/2018
 * Art. 12, with Proclamation Art. 98:
 *
 *  - 12(1): ordered by the head or a delegate, with the reason in writing,
 *    handed to the employee — or, if that is not possible, posted on the
 *    institution's website, social media or notice board for 10 consecutive days;
 *  - 12(2): salary withheld during the suspension is paid back, without
 *    interest, unless the employee is dismissed for the charged misconduct;
 *  - 12(3): rights and duties unrelated to the suspension continue;
 *  - 12(4): suspension from work and salary lasts at most three months.
 *
 * Additive; one row per suspension order on a case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disciplinary_suspensions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('case_id')->constrained('disciplinary_cases')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('ordered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('ordered_at');
            $table->text('reason');
            $table->boolean('without_pay')->default(false);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->dateTime('served_at');
            $table->string('service_method', 20);
            $table->date('posting_ends_on')->nullable();
            $table->date('lifted_on')->nullable();
            $table->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('lift_reason')->nullable();
            // Art. 12(2): null while the case is open; then due, not_due or paid.
            $table->string('back_pay_status', 20)->nullable();
            $table->date('back_pay_paid_on')->nullable();
            $table->text('back_pay_note')->nullable();
            $table->timestamps();

            $table->index(['case_id', 'lifted_on'], 'disciplinary_suspensions_case_lifted_idx');
            $table->index(['back_pay_status'], 'disciplinary_suspensions_back_pay_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_suspensions');
    }
};
