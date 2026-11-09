<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a grievance application must contain under Regulation 201/2018
 * Art. 32(2), and the Art. 33(1) prerequisite, captured on the employee's own
 * filing form (My Portal):
 *
 *  - applicant_address           (a)  the applicant's address
 *  - immediate_supervisor_name   (c)  the immediate supervisor or manager concerned
 *  - requested_remedy            (g)  the remedy the applicant seeks
 *  - applicant_declared_at       (h)  the applicant's dated declaration (signature)
 *  - written_issue_raised_at     Art. 33(1): when the matter was first presented
 *                                in writing to that supervisor; the 10-working-day
 *                                filing window runs from this date.
 *
 * Job title (b) is the existing position_id snapshot; the cause (d), date (e)
 * and evidence (f) are existing fields. Additive and nullable: grievances
 * filed before keep what they had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grievances', function (Blueprint $table): void {
            $table->timestamp('written_issue_raised_at')->nullable();
            $table->string('immediate_supervisor_name', 255)->nullable();
            $table->text('requested_remedy')->nullable();
            $table->string('applicant_address', 500)->nullable();
            $table->timestamp('applicant_declared_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('grievances', function (Blueprint $table): void {
            $table->dropColumn(['written_issue_raised_at', 'immediate_supervisor_name', 'requested_remedy', 'applicant_address', 'applicant_declared_at']);
        });
    }
};
