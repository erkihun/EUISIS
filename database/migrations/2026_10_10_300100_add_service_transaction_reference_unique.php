<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A provider transaction reference is an idempotency key: one row per
 * (provider, reference). Previously enforced only by a SELECT-then-INSERT
 * check, so a retried or double-posted transaction could be recorded twice
 * (production risk register CON-2). Existing data is not modified; the
 * migration stops and names duplicates for review instead.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'service_transactions_provider_reference_unique';

    public function up(): void
    {
        if (! Schema::hasTable('service_transactions') || Schema::hasIndex('service_transactions', self::INDEX)) {
            return;
        }

        $duplicates = DB::selectOne(
            'SELECT COUNT(*) AS n FROM (SELECT service_provider_id, reference FROM service_transactions '
            .'WHERE reference IS NOT NULL GROUP BY service_provider_id, reference HAVING COUNT(*) > 1) d'
        )->n;

        if ((int) $duplicates > 0) {
            throw new RuntimeException(
                "service_transactions has {$duplicates} duplicate (provider, reference) pair(s) — NEEDS_DATA_REVIEW. "
                .'Review with `php artisan data:audit-duplicates`, resolve, then re-run migrations.'
            );
        }

        $concurrently = DB::getDriverName() === 'pgsql' ? 'CONCURRENTLY ' : '';
        DB::statement('CREATE UNIQUE INDEX '.$concurrently.self::INDEX
            .' ON service_transactions (service_provider_id, reference) WHERE reference IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
