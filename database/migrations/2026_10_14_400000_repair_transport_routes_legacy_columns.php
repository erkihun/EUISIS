<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * transport_routes was first created by the domain foundation (2026_05_09)
 * with a NOT NULL transport_plan_id and name. The transport module's own
 * migration then skipped the table (it already existed) and the alignment
 * migration only added the module's columns. Every database therefore held a
 * table on which no route could be created, and without the module's
 * per-provider route-code uniqueness.
 *
 * Additive only: the two legacy columns become nullable (nothing reads them)
 * and the missing indexes are added. No column or row is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transport_routes')) {
            return;
        }

        Schema::table('transport_routes', function (Blueprint $table): void {
            if (Schema::hasColumn('transport_routes', 'transport_plan_id')) {
                $table->uuid('transport_plan_id')->nullable()->change();
            }
            if (Schema::hasColumn('transport_routes', 'name')) {
                $table->string('name')->nullable()->change();
            }
        });

        $indexes = collect(Schema::getIndexes('transport_routes'))->pluck('name')->all();

        Schema::table('transport_routes', function (Blueprint $table) use ($indexes): void {
            $duplicateCodes = DB::table('transport_routes')
                ->whereNotNull('provider_id')->whereNotNull('route_code')
                ->select('provider_id', 'route_code')->groupBy('provider_id', 'route_code')
                ->havingRaw('COUNT(*) > 1')->exists();

            if (! in_array('transport_routes_provider_id_route_code_unique', $indexes, true) && ! $duplicateCodes) {
                $table->unique(['provider_id', 'route_code']);
            }
            if (! in_array('transport_routes_provider_id_is_active_index', $indexes, true)) {
                $table->index(['provider_id', 'is_active']);
            }
            if (! in_array('transport_routes_assigned_organization_id_index', $indexes, true)) {
                $table->index('assigned_organization_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('transport_routes')) {
            return;
        }

        // The legacy columns stay nullable: forcing NOT NULL back could fail on
        // routes created since, and nothing depends on it.
        $indexes = collect(Schema::getIndexes('transport_routes'))->pluck('name')->all();
        Schema::table('transport_routes', function (Blueprint $table) use ($indexes): void {
            foreach (['transport_routes_provider_id_route_code_unique' => 'unique', 'transport_routes_provider_id_is_active_index' => 'index', 'transport_routes_assigned_organization_id_index' => 'index'] as $name => $kind) {
                if (in_array($name, $indexes, true)) {
                    $kind === 'unique' ? $table->dropUnique($name) : $table->dropIndex($name);
                }
            }
        });
    }
};
