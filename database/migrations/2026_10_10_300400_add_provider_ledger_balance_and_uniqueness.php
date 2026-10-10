<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provider ledger integrity and scale (production risk register CON-9):
 *
 *  - cafeteria_providers.ledger_balance: running balance kept under the
 *    provider row lock, so a scan no longer sums the provider's whole ledger;
 *  - one ledger entry per (transaction, entry type), so a retried scan or a
 *    double reversal cannot post twice.
 *
 * Existing data is not modified except to initialise the running balance from
 * the existing entries; duplicate entries stop the migration for review.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'cp_ledger_transaction_type_unique';

    public function up(): void
    {
        if (! Schema::hasColumn('cafeteria_providers', 'ledger_balance')) {
            Schema::table('cafeteria_providers', function (Blueprint $table): void {
                $table->decimal('ledger_balance', 14, 2)->default(0);
            });
            DB::statement('UPDATE cafeteria_providers SET ledger_balance = COALESCE((
                SELECT SUM(credit) - SUM(debit) FROM cafeteria_provider_ledger_entries l
                WHERE l.cafeteria_provider_id = cafeteria_providers.id), 0)');
        }

        if (Schema::hasIndex('cafeteria_provider_ledger_entries', self::INDEX)) {
            return;
        }

        $duplicates = DB::selectOne('SELECT COUNT(*) AS n FROM (SELECT cafeteria_transaction_id, entry_type
            FROM cafeteria_provider_ledger_entries WHERE cafeteria_transaction_id IS NOT NULL
            GROUP BY cafeteria_transaction_id, entry_type HAVING COUNT(*) > 1) d')->n;
        if ((int) $duplicates > 0) {
            throw new RuntimeException("cafeteria_provider_ledger_entries has {$duplicates} duplicated (transaction, entry type) posting(s) — NEEDS_DATA_REVIEW before the uniqueness constraint can be added.");
        }

        $concurrently = DB::getDriverName() === 'pgsql' ? 'CONCURRENTLY ' : '';
        DB::statement('CREATE UNIQUE INDEX '.$concurrently.self::INDEX
            .' ON cafeteria_provider_ledger_entries (cafeteria_transaction_id, entry_type) WHERE cafeteria_transaction_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        if (Schema::hasColumn('cafeteria_providers', 'ledger_balance')) {
            Schema::table('cafeteria_providers', function (Blueprint $table): void {
                $table->dropColumn('ledger_balance');
            });
        }
    }
};
