<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the columns that list, scope, join and dashboard queries use on
 * the high-volume tables (production risk register DB-1, DB-2, DB-3, DB-7).
 * On PostgreSQL a foreign key does not create an index, so every one of these
 * was a sequential scan at 3M employees.
 *
 * Safe on a live database: PostgreSQL builds each index CONCURRENTLY (outside
 * a transaction, no write lock) and IF NOT EXISTS; an index that already
 * exists under the same name — e.g. cafeteria indexes created by an earlier
 * migration path on some installations — is left alone.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @return list<array{0: string, 1: string, 2: string, 3?: string}> table, name, columns, partial-where */
    private function indexes(): array
    {
        $true = DB::getDriverName() === 'pgsql' ? 'true' : '1';

        return [
            ['employee_assignments', 'ea_employee_current_idx', 'employee_id, is_current'],
            ['employee_assignments', 'ea_organization_current_idx', 'organization_id, is_current'],
            ['employee_assignments', 'ea_position_idx', 'position_id'],
            ['id_cards', 'id_cards_employee_idx', 'employee_id, is_current'],
            ['employee_duplicate_flags', 'edf_employee_idx', 'employee_id'],
            ['employee_documents', 'employee_documents_employee_idx', 'employee_id'],
            ['positions', 'positions_organization_unit_idx', 'organization_unit_id'],
            ['card_verifications', 'card_verifications_created_idx', 'created_at'],
            ['notifications', 'notifications_notifiable_created_idx', 'notifiable_type, notifiable_id, created_at'],
            ['notifications', 'notifications_unread_idx', 'notifiable_type, notifiable_id', 'read_at IS NULL'],
            ['audit_logs', 'audit_logs_organization_created_idx', 'organization_id, created_at'],
            ['audit_logs', 'audit_logs_actor_idx', 'actor_user_id'],
            ['daily_activity_reminders', 'dar_date_type_employee_idx', 'activity_date, reminder_type, employee_id'],
            ['employees', 'employees_created_at_idx', 'created_at'],
            ['employees', 'employees_updated_at_idx', 'updated_at'],
            ['cafeteria_transactions', 'ct_employee_date_idx', 'employee_id, transaction_date'],
            ['cafeteria_transactions', 'ct_provider_date_idx', 'cafeteria_provider_id, transaction_date'],
            ['service_transactions', 'st_occurred_at_idx', 'occurred_at'],
            ['employee_service_feedback', 'esf_position_service_idx', 'position_service_id'],
        ];
    }

    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';

        foreach ($this->indexes() as $index) {
            [$table, $name, $columns] = $index;
            $where = $index[3] ?? null;

            if (! Schema::hasTable($table) || Schema::hasIndex($table, $name) || ! $this->hasColumns($table, $columns)) {
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
            $this->trigramSearchIndexes();
        }
    }

    /**
     * Employee search uses ILIKE '%term%' on name and number columns; only a
     * trigram GIN index can serve a leading wildcard. Needs the pg_trgm
     * extension — if the application role may not create it, a DBA must run
     * `CREATE EXTENSION pg_trgm;` once and re-run this migration's indexes.
     */
    private function trigramSearchIndexes(): void
    {
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (Throwable $e) {
            Log::warning('pg_trgm unavailable; employee search trigram indexes skipped. A DBA must run CREATE EXTENSION pg_trgm.', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        foreach (['full_name', 'name_en', 'employee_number'] as $column) {
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS employees_{$column}_trgm_idx ON employees USING gin ({$column} gin_trgm_ops)");
        }
    }

    private function hasColumns(string $table, string $columns): bool
    {
        foreach (array_map('trim', explode(',', $columns)) as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    public function down(): void
    {
        foreach ($this->indexes() as [$table, $name]) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                DB::statement("DROP INDEX IF EXISTS {$name}");
            }
        }
        foreach (['full_name', 'name_en', 'employee_number'] as $column) {
            DB::statement("DROP INDEX IF EXISTS employees_{$column}_trgm_idx");
        }
    }
};
