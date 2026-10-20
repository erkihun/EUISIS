<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database backstops for the boarding invariants (docs/transport/business-rules.md):
 *
 *  - one accepted, un-voided boarding per employee per trip
 *  - one trip per schedule per service date
 *
 * The scan service also checks both under a row lock; the index is what
 * makes two scanners racing on the same card produce one boarding. Existing
 * data is never modified: a violation stops the migration and names itself
 * (NEEDS_DATA_REVIEW). PostgreSQL builds CONCURRENTLY, outside a transaction.
 * MySQL/MariaDB have no partial indexes: a STORED generated column holds the
 * key only for rows the condition covers (NULL otherwise, and NULLs never
 * collide), and a plain unique index goes on that column.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @return array<string, array{table: string, columns: string, where: string, generated: string}> */
    private function constraints(): array
    {
        return [
            'ttx_one_accepted_boarding_per_trip' => [
                'table' => 'transport_transactions',
                'columns' => 'transport_trip_id, employee_id',
                'where' => "status = 'accepted' AND voided_at IS NULL AND transport_trip_id IS NOT NULL",
                'generated' => 'ttx_boarding_key',
            ],
            'tt_one_trip_per_schedule_date' => [
                'table' => 'transport_trips',
                'columns' => 'transport_schedule_id, trip_date',
                'where' => "transport_schedule_id IS NOT NULL AND status <> 'cancelled'",
                'generated' => 'tt_schedule_date_key',
            ],
        ];
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
                $violations[] = "{$name}: {$duplicates} duplicate ({$c['columns']}) value(s) in {$c['table']}";
            }
        }

        if ($violations !== []) {
            throw new RuntimeException(
                "Transport integrity indexes not added — existing data violates them (NEEDS_DATA_REVIEW):\n  - "
                .implode("\n  - ", $violations)
                ."\nVoid the duplicate boardings (keep the earliest) and re-run migrations."
            );
        }

        $concurrently = DB::getDriverName() === 'pgsql' ? 'CONCURRENTLY ' : '';
        foreach ($this->constraints() as $name => $c) {
            if (! Schema::hasTable($c['table']) || Schema::hasIndex($c['table'], $name)) {
                continue;
            }
            if (! $this->mysql()) {
                DB::statement("CREATE UNIQUE INDEX {$concurrently}{$name} ON {$c['table']} ({$c['columns']}) WHERE {$c['where']}");

                continue;
            }
            if (! Schema::hasColumn($c['table'], $c['generated'])) {
                $parts = implode(", '|', ", array_map('trim', explode(',', $c['columns'])));
                DB::statement("ALTER TABLE {$c['table']} ADD COLUMN {$c['generated']} VARCHAR(100) GENERATED ALWAYS AS (CASE WHEN {$c['where']} THEN CONCAT({$parts}) ELSE NULL END) STORED");
            }
            DB::statement("CREATE UNIQUE INDEX {$name} ON {$c['table']} ({$c['generated']})");
        }
    }

    private function mysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function down(): void
    {
        foreach ($this->constraints() as $name => $c) {
            if (Schema::hasTable($c['table']) && Schema::hasIndex($c['table'], $name)) {
                Schema::table($c['table'], fn ($table) => $table->dropIndex($name));
            }
            if ($this->mysql() && Schema::hasTable($c['table']) && Schema::hasColumn($c['table'], $c['generated'])) {
                Schema::table($c['table'], fn ($table) => $table->dropColumn($c['generated']));
            }
        }
    }
};
