<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An initial route may go to "all committees", scoped (owner request 2026-11-07):
 * each grievance goes to its own institution's grievance committee, so
 * "All institutions → All committees" never sends a case to another
 * institution. Such a route has no target handler id; `target_all` marks it.
 * Guarded for retries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grievance_routes', function (Blueprint $table): void {
            if (! Schema::hasColumn('grievance_routes', 'target_all')) {
                $table->boolean('target_all')->default(false)->after('target_handler_id');
            }
        });
        Schema::table('grievance_routes', function (Blueprint $table): void {
            $table->uuid('target_handler_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Only reversible while every route names its target.
        if (! DB::table('grievance_routes')->whereNull('target_handler_id')->exists()) {
            Schema::table('grievance_routes', function (Blueprint $table): void {
                $table->uuid('target_handler_id')->nullable(false)->change();
            });
        }
        Schema::table('grievance_routes', function (Blueprint $table): void {
            if (Schema::hasColumn('grievance_routes', 'target_all')) {
                $table->dropColumn('target_all');
            }
        });
    }
};
