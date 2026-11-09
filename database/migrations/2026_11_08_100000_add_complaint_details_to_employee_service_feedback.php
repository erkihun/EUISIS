<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public complaint form's extra details (owner's template, 2026-11-08):
 * identity document, representation, prior handling at woreda and sub-city,
 * remedy sought, attached documents and the declaration. Encrypted JSON
 * (`encrypted:array`), like the submitter's address. Guarded for retries.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employee_service_feedback', 'complaint_details')) {
            Schema::table('employee_service_feedback', function (Blueprint $table): void {
                $table->text('complaint_details')->nullable()->after('submitter_address');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employee_service_feedback', 'complaint_details')) {
            Schema::table('employee_service_feedback', function (Blueprint $table): void {
                $table->dropColumn('complaint_details');
            });
        }
    }
};
