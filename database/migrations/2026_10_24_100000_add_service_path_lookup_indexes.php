<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the card-scan and integration paths, measured on the 3M-employee
 * benchmark database (docs/production-readiness/database-capacity.md):
 *
 *  - entitlements (employee_id, service_type_id): Entitlement::inForceFor runs
 *    twice per service transaction and on every card verification; without it
 *    each call was a sequential scan of one row per employee.
 *  - card_verifications (id_card_id) and service_transactions (employee_id,
 *    occurred_at): foreign keys PostgreSQL does not index by itself, read by
 *    the card and employee history views.
 *  - integration_events (id) WHERE status = 'pending': the outbox publisher's
 *    claim (status = 'pending' ORDER BY id LIMIT n) reads the head of this small
 *    partial index instead of sorting every pending row after a bulk change.
 *
 * Safe on a live database: PostgreSQL builds each index CONCURRENTLY and IF NOT
 * EXISTS outside a transaction. MySQL/MariaDB get plain indexes (no partial
 * indexes there, so the outbox index covers (status, id)). Names stay under 64
 * characters for MySQL.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @return list<array{0: string, 1: string, 2: string, 3?: string, 4?: string}> table, name, columns, partial-where (PostgreSQL/SQLite), columns without partial indexes */
    private function indexes(): array
    {
        return [
            ['entitlements', 'entitlements_employee_service_idx', 'employee_id, service_type_id'],
            ['card_verifications', 'card_verifications_card_idx', 'id_card_id'],
            ['service_transactions', 'st_employee_occurred_idx', 'employee_id, occurred_at'],
            ['integration_events', 'integration_events_pending_id_idx', 'id', "status = 'pending'", 'status, id'],
        ];
    }

    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';
        $partial = ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);

        foreach ($this->indexes() as $index) {
            [$table, $name, $columns] = $index;
            $where = $partial ? ($index[3] ?? null) : null;
            if (! $partial && isset($index[4])) {
                $columns = $index[4];
            }
            if (! Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
                continue;
            }

            DB::statement(sprintf(
                'CREATE INDEX %s%s ON %s (%s)%s',
                $pgsql ? 'CONCURRENTLY IF NOT EXISTS ' : '',
                $name,
                $table,
                $columns,
                $where !== null ? " WHERE {$where}" : '',
            ));
        }

        if ($pgsql) {
            $this->postgresSearchIndexes();
        }
    }

    /**
     * PostgreSQL only (MySQL collations are case-insensitive, so its plain
     * unique indexes already serve these):
     *  - lower(code) with text_pattern_ops serves the API's case-insensitive
     *    code equality (lower(col) = lower(?)) and the registry search's
     *    employee-number prefix (lower(col) LIKE 'abc%'); ILIKE on the trigram
     *    index took ~0.7–1.5 s per call at 3M employees.
     *  - trigram indexes on e-mail and phone, used only when the search looks
     *    like an e-mail address or a phone number. Partial: most rows have none.
     * Every statement is IF NOT EXISTS, so a re-run after a failure resumes.
     */
    private function postgresSearchIndexes(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS employees_number_lower_idx ON employees (lower(employee_number) text_pattern_ops)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS positions_code_lower_idx ON positions (lower(job_position_code) text_pattern_ops)');

        if (DB::selectOne("SELECT 1 AS ok FROM pg_extension WHERE extname = 'pg_trgm'") !== null) {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS employees_email_trgm_idx ON employees USING gin (email gin_trgm_ops) WHERE email IS NOT NULL');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS employees_phone_trgm_idx ON employees USING gin (phone gin_trgm_ops) WHERE phone IS NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['employees_number_lower_idx', 'positions_code_lower_idx', 'employees_email_trgm_idx', 'employees_phone_trgm_idx'] as $name) {
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            }
        }

        foreach ($this->indexes() as [$table, $name]) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                try {
                    Schema::table($table, fn ($t) => $t->dropIndex($name));
                } catch (QueryException $e) {
                    // InnoDB adopts a new index that starts with a foreign-key
                    // column as that key's index and refuses to drop it (1553).
                    // Keeping it is harmless; anything else is a real failure.
                    if (! str_contains($e->getMessage(), '1553')) {
                        throw $e;
                    }
                }
            }
        }
    }
};
