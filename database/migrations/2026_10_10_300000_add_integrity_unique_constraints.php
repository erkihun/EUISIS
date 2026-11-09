<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database backstops for invariants that were enforced only by application
 * locks (production risk register CON-1, CON-3, CON-4):
 *
 *  - one live, current ID card per employee
 *  - one current assignment per employee
 *  - one completed employee transfer per vacancy application
 *
 * Existing data is never modified: if a violation already exists the
 * migration stops and names it, so it can be reviewed (NEEDS_DATA_REVIEW)
 * with `php artisan data:audit-duplicates` before the constraint is added.
 *
 * On PostgreSQL the indexes are built CONCURRENTLY (outside a transaction)
 * so a large live table is not write-locked while they build. MySQL and
 * MariaDB have no partial indexes: there, a stored generated column carries
 * the key only for the rows the condition selects and the unique index sits
 * on it (a NULL never collides), and an "IS NOT NULL" condition is simply a
 * plain unique index.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const LIVE_CARD_STATUSES = "'pending_print','printed','issued','active'";

    /** @return array<string, array{table: string, columns: string, where: string, generated: ?array{column: string, type: string}}> */
    private function constraints(): array
    {
        $true = DB::getDriverName() === 'pgsql' ? 'true' : '1';

        return [
            'id_cards_one_live_current_per_employee' => [
                'table' => 'id_cards',
                'columns' => 'employee_id',
                'where' => "is_current = {$true} AND status IN (".self::LIVE_CARD_STATUSES.')',
                'generated' => ['column' => 'live_current_employee_id', 'type' => 'CHAR(36)'],
            ],
            'employee_assignments_one_current_per_employee' => [
                'table' => 'employee_assignments',
                'columns' => 'employee_id',
                'where' => "is_current = {$true}",
                'generated' => ['column' => 'current_employee_id', 'type' => 'CHAR(36)'],
            ],
            'employee_transfers_one_per_vacancy_application' => [
                'table' => 'employee_transfers',
                'columns' => 'vacancy_application_id',
                'where' => 'vacancy_application_id IS NOT NULL',
                'generated' => null,
            ],
        ];
    }

    private function partialIndexes(): bool
    {
        return ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function up(): void
    {
        $violations = [];
        foreach ($this->constraints() as $name => $c) {
            if (! Schema::hasTable($c['table'])) {
                continue;
            }
            $duplicates = DB::selectOne(
                "SELECT COUNT(*) AS n FROM (SELECT {$c['columns']} FROM {$c['table']} WHERE {$c['where']} GROUP BY {$c['columns']} HAVING COUNT(*) > 1) d"
            )->n;
            if ((int) $duplicates > 0) {
                $violations[] = "{$name}: {$duplicates} duplicate {$c['columns']} value(s) in {$c['table']}";
            }
        }

        if ($violations !== []) {
            throw new RuntimeException(
                "Integrity constraints not added — existing data violates them (NEEDS_DATA_REVIEW):\n  - "
                .implode("\n  - ", $violations)
                ."\nReview with `php artisan data:audit-duplicates`, resolve the records, then re-run migrations."
            );
        }

        $concurrently = DB::getDriverName() === 'pgsql' ? 'CONCURRENTLY ' : '';
        foreach ($this->constraints() as $name => $c) {
            if (! Schema::hasTable($c['table']) || Schema::hasIndex($c['table'], $name)) {
                continue;
            }
            if ($this->partialIndexes()) {
                DB::statement("CREATE UNIQUE INDEX {$concurrently}{$name} ON {$c['table']} ({$c['columns']}) WHERE {$c['where']}");

                continue;
            }
            $columns = $c['columns'];
            if ($c['generated'] !== null) {
                $columns = $c['generated']['column'];
                if (! Schema::hasColumn($c['table'], $columns)) {
                    DB::statement("ALTER TABLE {$c['table']} ADD COLUMN {$columns} {$c['generated']['type']} GENERATED ALWAYS AS (CASE WHEN {$c['where']} THEN {$c['columns']} ELSE NULL END) STORED");
                }
            }
            DB::statement("CREATE UNIQUE INDEX {$name} ON {$c['table']} ({$columns})");
        }
    }

    public function down(): void
    {
        foreach ($this->constraints() as $name => $c) {
            if (! Schema::hasTable($c['table'])) {
                continue;
            }
            if (Schema::hasIndex($c['table'], $name)) {
                Schema::table($c['table'], fn ($table) => $table->dropIndex($name));
            }
            if (! $this->partialIndexes() && $c['generated'] !== null && Schema::hasColumn($c['table'], $c['generated']['column'])) {
                Schema::table($c['table'], fn ($table) => $table->dropColumn($c['generated']['column']));
            }
        }
    }
};
