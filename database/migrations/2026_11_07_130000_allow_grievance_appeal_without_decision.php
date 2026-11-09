<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0030: when the Grievance Directorate does not decide in time, the
 * employee may take the case to the Administrative Court without a decision.
 * Such an appeal has no appealed decision, so the column becomes nullable.
 * The unique index stays (one appeal per decision; NULLs never collide).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grievance_appeals', function (Blueprint $table): void {
            $table->uuid('appealed_decision_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Only reversible while every appeal still names a decision.
        if (DB::table('grievance_appeals')->whereNull('appealed_decision_id')->exists()) {
            return;
        }
        Schema::table('grievance_appeals', function (Blueprint $table): void {
            $table->uuid('appealed_decision_id')->nullable(false)->change();
        });
    }
};
