<?php

declare(strict_types=1);

// migration-safety: reviewed — one new table and one nullable-with-default column on a table created today.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0035: a public complaint is heard in up to three stages — the complaint
 * committee, the appeal committee (አቤቱታ ሰሚ ኮሚቴ) and the head of the
 * institution. One row per stage keeps who decided what, the response given
 * to the complainant, and what the complainant did with it (accepted,
 * appealed, or let the appeal period lapse).
 *
 * A complaint handling unit says which stage it serves. Guarded so a retry
 * on MySQL can resume.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('public_case_handling_units', 'stage')) {
            Schema::table('public_case_handling_units', function (Blueprint $table): void {
                // first_instance, appeal, head_office — complaint units only; others keep first_instance.
                $table->string('stage', 20)->default('first_instance')->after('case_type');
            });
        }

        if (! Schema::hasTable('public_complaint_stages')) {
            Schema::create('public_complaint_stages', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                // The complaint (public_complaints); no foreign key, like the other case records (ADR 0032).
                $table->uuid('submission_id');
                $table->unsignedTinyInteger('stage'); // 1 committee, 2 appeal committee, 3 head
                $table->dateTime('opened_at');
                $table->text('appeal_reason')->nullable(); // why the complainant appealed into this stage
                $table->string('decision', 20)->nullable(); // answered (stage 1), upheld, overturned
                $table->text('response')->nullable(); // what the complainant is told
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->dateTime('decided_at')->nullable();
                $table->dateTime('appeal_deadline_at')->nullable();
                $table->string('citizen_outcome', 20)->nullable(); // accepted, appealed, lapsed
                $table->dateTime('citizen_responded_at')->nullable();
                $table->timestamps();

                $table->unique(['submission_id', 'stage'], 'pcs_submission_stage_unique');
                $table->index(['citizen_outcome', 'appeal_deadline_at'], 'pcs_outcome_deadline_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('public_complaint_stages');
        if (Schema::hasColumn('public_case_handling_units', 'stage')) {
            Schema::table('public_case_handling_units', function (Blueprint $table): void {
                $table->dropColumn('stage');
            });
        }
    }
};
