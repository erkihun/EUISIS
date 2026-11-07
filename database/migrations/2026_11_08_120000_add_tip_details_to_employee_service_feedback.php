<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public Tip-off form's extra details (owner's sample, 2026-11-08):
 * confidentiality request, the person suspected, violations alleged and a
 * description of the supporting evidence. Encrypted JSON (`encrypted:array`).
 * Guarded for retries.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employee_service_feedback', 'tip_details')) {
            Schema::table('employee_service_feedback', function (Blueprint $table): void {
                $table->text('tip_details')->nullable()->after('complaint_details');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employee_service_feedback', 'tip_details')) {
            Schema::table('employee_service_feedback', function (Blueprint $table): void {
                $table->dropColumn('tip_details');
            });
        }
    }
};
