<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The language a public submissions export was requested in. The export is
 * built later by a queued job, which has no request and so no visitor
 * language: the job reads this to write Amharic labels and names with
 * Ethiopian dates, or English with Gregorian dates. Additive and nullable:
 * older exports keep the application default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_feedback_report_exports', function (Blueprint $table): void {
            $table->string('locale', 5)->nullable()->after('format');
        });
    }

    public function down(): void
    {
        Schema::table('public_feedback_report_exports', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
