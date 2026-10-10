<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The position list and status pages order an organization's positions by
 * (organization, unit, code). With only single-column indexes PostgreSQL
 * walked the unit index across every position in the city and filtered out
 * all but one organization's rows (load test 2026-10-11: 219,500 rows removed
 * to return 15). This composite index serves the filter and the order.
 *
 * Safe on a live database: built CONCURRENTLY IF NOT EXISTS, outside a
 * transaction, without a write lock.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const NAME = 'positions_org_unit_code_idx';

    public function up(): void
    {
        if (Schema::hasIndex('positions', self::NAME)) {
            return;
        }

        $concurrently = DB::getDriverName() === 'pgsql' ? 'CONCURRENTLY IF NOT EXISTS ' : '';
        DB::statement('CREATE INDEX '.$concurrently.self::NAME.' ON positions (organization_id, organization_unit_id, job_position_code)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::NAME);
    }
};
