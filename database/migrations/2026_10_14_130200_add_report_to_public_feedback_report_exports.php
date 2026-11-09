<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which report a queued export holds: the existing submissions report, or a
 * Situation Room aggregate. Existing rows keep "submissions". Guarded so a
 * retry on MySQL resumes instead of failing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('public_feedback_report_exports') && ! Schema::hasColumn('public_feedback_report_exports', 'report')) {
            Schema::table('public_feedback_report_exports', function (Blueprint $table): void {
                $table->string('report', 40)->default('submissions')->after('organization_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('public_feedback_report_exports', 'report')) {
            Schema::table('public_feedback_report_exports', function (Blueprint $table): void {
                $table->dropColumn('report');
            });
        }
    }
};
