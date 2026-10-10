<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One employee per national ID in the database (risk register CON-10).
 * Validation already refused a known duplicate; this closes the race between
 * two simultaneous registrations.
 *
 * Existing data is never changed: if any national ID is shared the migration
 * stops with NEEDS_DATA_REVIEW — run `php artisan data:audit-duplicates`,
 * resolve each pair, then migrate again. Built CONCURRENTLY on PostgreSQL.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const NAME = 'employees_national_id_hash_unique';

    public function up(): void
    {
        if (Schema::hasIndex('employees', self::NAME)) {
            return;
        }

        $shared = (int) DB::selectOne('SELECT COUNT(*) AS n FROM (SELECT national_id_hash FROM employees
            WHERE national_id_hash IS NOT NULL GROUP BY national_id_hash HAVING COUNT(*) > 1) d')->n;
        if ($shared > 0) {
            throw new RuntimeException("{$shared} national ID(s) are shared by more than one employee — NEEDS_DATA_REVIEW: run data:audit-duplicates and resolve them before this migration.");
        }

        $concurrently = DB::getDriverName() === 'pgsql' ? 'CONCURRENTLY ' : '';
        DB::statement('CREATE UNIQUE INDEX '.$concurrently.self::NAME.' ON employees (national_id_hash) WHERE national_id_hash IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::NAME);
    }
};
