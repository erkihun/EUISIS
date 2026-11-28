<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An approved planned period is counted as leave by the system on its first
 * day (owner decision, 2026-11-27). When that cannot be done (not enough
 * days left, the days are already taken by another request) the reason is
 * kept on the period for the HR officer to see.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('leave_plans', 'auto_error')) {
            return;
        }
        Schema::table('leave_plans', function (Blueprint $table): void {
            $table->string('auto_error', 500)->nullable()->after('leave_request_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('leave_plans', 'auto_error')) {
            Schema::table('leave_plans', fn (Blueprint $table) => $table->dropColumn('auto_error'));
        }
    }
};
