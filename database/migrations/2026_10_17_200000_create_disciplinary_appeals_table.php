<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regulation 201/2018 Part Five: the employee's appeal to the Administrative
 * Court against a delivered disciplinary decision, as far as the institution
 * must act on it (docs/regulation-201-2018-traceability.md):
 *
 *  - Art. 37: the appeal notice and the request for a certified copy of the
 *    committee record, which the institution must give within 5 consecutive
 *    working days;
 *  - Art. 29: while the appeal is pending, the penalty is not executed;
 *  - Art. 53: a remand sends the case back for a new decision within 15
 *    consecutive working days;
 *  - Art. 54: a ruling for the appellant is implemented within 10 days.
 *
 * The court's own procedure (Arts. 40–52) is external; only its dates and
 * outcome are recorded. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disciplinary_appeals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('case_id')->constrained('disciplinary_cases')->cascadeOnDelete();
            $table->foreignUuid('decision_id')->constrained('disciplinary_decisions')->restrictOnDelete();
            // Art. 37(1): the employee's notice to the institution, and whether it asks for the committee record.
            $table->dateTime('notice_received_at');
            $table->boolean('record_copy_requested')->default(false);
            // Art. 37(2): the sealed, certified copy handed over.
            $table->dateTime('record_copy_delivered_at')->nullable();
            $table->foreignId('record_copy_certified_by')->nullable()->constrained('users')->nullOnDelete();
            // Art. 38/40: filed with the Administrative Court.
            $table->date('filed_on')->nullable();
            $table->string('court_case_number', 100)->nullable();
            // Arts. 42, 52, 53: the court's outcome as received.
            $table->string('outcome', 20)->nullable();
            $table->date('outcome_decided_on')->nullable();
            $table->dateTime('outcome_received_at')->nullable();
            $table->text('outcome_summary')->nullable();
            // Art. 54: a ruling for the appellant carried out.
            $table->dateTime('implemented_at')->nullable();
            $table->text('implementation_note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('decision_id', 'disciplinary_appeals_decision_unique');
            $table->index(['case_id', 'outcome'], 'disciplinary_appeals_case_outcome_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_appeals');
    }
};
