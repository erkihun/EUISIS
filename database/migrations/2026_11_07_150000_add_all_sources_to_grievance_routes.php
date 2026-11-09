<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A route may start from "all" handlers of one kind (owner request 2026-11-07),
 * e.g. every Grievance Team → the Grievance Directorate. Such a route has no
 * source handler id; `source_all` marks it and `source_unit_kind` says which
 * kind of unit (team/directorate) for organization-unit sources. A route from a
 * specific handler is still preferred over an "all" route. Guarded for retries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grievance_routes', function (Blueprint $table): void {
            if (! Schema::hasColumn('grievance_routes', 'source_all')) {
                $table->boolean('source_all')->default(false)->after('source_handler_id');
            }
            if (! Schema::hasColumn('grievance_routes', 'source_unit_kind')) {
                $table->string('source_unit_kind', 20)->nullable()->after('source_all');
            }
        });
        Schema::table('grievance_routes', function (Blueprint $table): void {
            $table->uuid('source_handler_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Only reversible while every route names its source.
        if (! DB::table('grievance_routes')->whereNull('source_handler_id')->exists()) {
            Schema::table('grievance_routes', function (Blueprint $table): void {
                $table->uuid('source_handler_id')->nullable(false)->change();
            });
        }
        Schema::table('grievance_routes', function (Blueprint $table): void {
            foreach (['source_unit_kind', 'source_all'] as $column) {
                if (Schema::hasColumn('grievance_routes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
