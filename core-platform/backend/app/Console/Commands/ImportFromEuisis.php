<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\LegacyCredentialBackfill;
use App\Support\PositionMasterBackfill;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copies the Golden Path tables from the EUISIS database (connection `euisis`)
 * into the Core database, preserving every identifier: UUIDs, employee numbers,
 * card numbers and public card references are copied as they are, never
 * regenerated. Only columns present on both sides are copied; generated columns
 * are left to the database.
 *
 * Run it into an empty Core database (it refuses otherwise unless --fresh-tables
 * is given, which empties only the Core Golden Path tables first). Read-only on
 * EUISIS. Afterwards, compare fingerprints:
 *
 *   (EUISIS)  php artisan core:snapshot --output=euisis.json
 *   (Core)    php artisan core:snapshot --compare=euisis.json
 */
class ImportFromEuisis extends Command
{
    protected $signature = 'core:import-from-euisis {--fresh-tables : Empty the Core Golden Path tables before copying} {--chunk=500 : Rows per batch}';

    protected $description = 'Copy the Golden Path tables from the EUISIS database into the Core database, identifiers unchanged';

    /** Parents before children, so the copy holds even where foreign keys cannot be switched off. */
    private const ORDER = [
        'organization_types', 'organizations', 'hierarchy_versions', 'organization_edges', 'organization_unit_types',
        'organization_units', 'positions', 'position_establishments', 'employees', 'employee_assignments',
        'position_occupancies', 'card_requests', 'id_cards', 'card_replacements',
    ];

    /** Master data copied first when EUISIS has it, so positions keep their references (ADR 0010). */
    private const MASTERS = ['occupations'];

    /** Pointers that close a loop: copied empty first, filled once every row exists. */
    private const DEFERRED = [
        'employees' => ['current_assignment_id'],
        'id_cards' => ['previous_card_id'],
    ];

    public function handle(): int
    {
        $tables = array_values(array_intersect(self::ORDER, array_keys((array) config('core.tables'))));
        $generated = (array) config('core.generated_columns');
        $source = DB::connection('euisis');
        $chunk = max(50, (int) $this->option('chunk'));

        $notEmpty = array_filter($tables, fn (string $table): bool => DB::table($table)->exists());
        if ($notEmpty !== [] && ! $this->option('fresh-tables')) {
            $this->error('The Core database already holds Golden Path rows ('.implode(', ', $notEmpty).'). Use --fresh-tables to replace them.');

            return self::FAILURE;
        }

        Schema::disableForeignKeyConstraints();
        try {
            if ($this->option('fresh-tables')) {
                // Credentials belong to the cards being replaced; the backfill below gives the copies theirs.
                if (Schema::hasTable('digital_credentials')) {
                    DB::table('digital_credentials')->delete();
                }
                foreach (array_reverse($tables) as $table) {
                    DB::table($table)->delete();
                }
            }

            $rows = [];
            foreach (self::MASTERS as $table) {
                if ($source->getSchemaBuilder()->hasTable($table) && ! DB::table($table)->exists()) {
                    $columns = array_values(array_intersect(Schema::getColumnListing($table), $source->getSchemaBuilder()->getColumnListing($table)));
                    $source->table($table)->select($columns)->chunkById($this->rowsPerInsert($chunk, $columns), fn ($batch) => DB::table($table)->insert($batch->map(fn ($row): array => (array) $row)->all()));
                    $rows[] = [$table, DB::table($table)->count()];
                }
            }
            foreach ($tables as $table) {
                if (! $source->getSchemaBuilder()->hasTable($table)) {
                    $this->warn("EUISIS has no {$table} table; skipped.");
                    $rows[] = [$table, 0];

                    continue;
                }
                $columns = array_values(array_diff(
                    array_intersect(Schema::getColumnListing($table), $source->getSchemaBuilder()->getColumnListing($table)),
                    $generated[$table] ?? [],
                ));

                $deferred = array_values(array_intersect(self::DEFERRED[$table] ?? [], $columns));
                $copied = 0;
                $source->table($table)->select($columns)->chunkById($this->rowsPerInsert($chunk, $columns), function ($batch) use ($table, $deferred, &$copied): void {
                    DB::table($table)->insert($batch->map(fn ($row): array => array_merge((array) $row, array_fill_keys($deferred, null)))->all());
                    $copied += $batch->count();
                });
                $rows[] = [$table, $copied];
            }

            foreach (self::DEFERRED as $table => $pointers) {
                if (! $source->getSchemaBuilder()->hasTable($table)) {
                    continue;
                }
                foreach ($pointers as $pointer) {
                    $source->table($table)->whereNotNull($pointer)->select(['id', $pointer])->orderBy('id')->chunk($chunk, function ($batch) use ($table, $pointer): void {
                        foreach ($batch as $row) {
                            DB::table($table)->where('id', $row->id)->update([$pointer => $row->{$pointer}]);
                        }
                    });
                }
            }
            PositionMasterBackfill::run();
            // Printed QR codes of copied cards keep verifying (ADR 0012): the migration only covered the cards present then.
            $rows[] = ['digital_credentials (legacy QR)', LegacyCredentialBackfill::run()];
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->table(['Table', 'Rows copied'], $rows);
        $this->info('Done. Compare fingerprints with core:snapshot before any switch.');

        return self::SUCCESS;
    }

    /**
     * Rows per insert: the --chunk asked for, but never more bound values than the
     * database takes in one statement (PostgreSQL: 65,535). A wide table such as
     * id_cards (35 columns) with --chunk=2000 would otherwise fail part-way (ADR 0012).
     *
     * @param  list<string>  $columns
     */
    private function rowsPerInsert(int $chunk, array $columns): int
    {
        return max(1, min($chunk, intdiv(60000, max(1, count($columns)))));
    }
}
