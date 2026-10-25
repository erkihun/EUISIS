<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;

/**
 * The Golden Path fingerprint: per table, the row count and a SHA-256 over the
 * identifying columns of every row in id order, plus the status counts. The
 * algorithm and the table list are the same as EUISIS `core:snapshot`, so a file
 * taken from either database can be compared on the other: the proof that a
 * copy kept every identifier and state. Read-only; prints no values.
 */
class CoreSnapshot extends Command
{
    protected $signature = 'core:snapshot {--output= : Write the snapshot JSON to this file} {--compare= : Compare with a snapshot file and fail on any difference}';

    protected $description = 'Fingerprint the Golden Path tables (counts, checksums, status counts); compatible with EUISIS core:snapshot';

    private const CHUNK = 2000;

    private const STATUS_COLUMNS = ['status', 'assignment_status'];

    public function handle(): int
    {
        $snapshot = ['taken_at' => now()->toIso8601String(), 'connection' => DB::getDefaultConnection(), 'tables' => []];
        foreach ((array) config('core.tables') as $table => $columns) {
            $snapshot['tables'][$table] = Schema::hasTable($table) ? $this->fingerprint($table, $columns) : null;
        }

        $json = (string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (($output = $this->option('output')) !== null) {
            file_put_contents((string) $output, $json);
            $this->info('Snapshot written to '.$output.'.');
        }
        if (($compare = $this->option('compare')) !== null) {
            return $this->compare($snapshot, (string) $compare);
        }
        if ($output === null) {
            $this->line($json);
        }

        return self::SUCCESS;
    }

    /** @param list<string> $columns */
    private function fingerprint(string $table, array $columns): array
    {
        $columns = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn($table, $column)));
        $hash = hash_init('sha256');
        $count = 0;
        $lastId = null;

        do {
            $rows = DB::table($table)->select($columns)
                ->when($lastId !== null, fn ($query) => $query->where('id', '>', $lastId))
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get();
            foreach ($rows as $row) {
                $values = array_map(fn (string $column): string => $this->normalise($row->{$column} ?? null), $columns);
                hash_update($hash, implode("\x1f", $values)."\x1e");
                $count++;
                $lastId = $row->id;
            }
        } while ($rows->count() === self::CHUNK);

        $result = ['count' => $count, 'sha256' => hash_final($hash)];
        foreach (array_intersect(self::STATUS_COLUMNS, $columns) as $statusColumn) {
            $result['statuses'][$statusColumn] = DB::table($table)
                ->selectRaw($statusColumn.' as value, count(*) as total')
                ->groupBy($statusColumn)
                ->get()
                ->mapWithKeys(fn ($row): array => [$this->normalise($row->value) => (int) $row->total])
                ->sortKeys()
                ->all();
        }

        return $result;
    }

    /** One spelling per value on every engine: booleans as 0/1, dates as Y-m-d, null as empty. */
    private function normalise(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}( 00:00:00)?$/', $value) === 1 => substr($value, 0, 10),
            default => strtolower((string) $value) === 'true' ? '1' : (strtolower((string) $value) === 'false' ? '0' : (string) $value),
        };
    }

    private function compare(array $current, string $path): int
    {
        try {
            $previous = json_decode((string) @file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('Cannot read snapshot '.$path.'.');

            return self::FAILURE;
        }

        $rows = [];
        $differences = 0;
        foreach (array_keys((array) config('core.tables')) as $table) {
            $before = $previous['tables'][$table] ?? null;
            $after = $current['tables'][$table] ?? null;
            $same = ($before['count'] ?? null) === ($after['count'] ?? null) && ($before['sha256'] ?? null) === ($after['sha256'] ?? null);
            $differences += $same ? 0 : 1;
            $rows[] = [$table, $before['count'] ?? '-', $after['count'] ?? '-', $same ? 'same' : 'DIFFERENT'];
        }
        $this->table(['Table', 'Snapshot', 'This database', 'Checksum'], $rows);

        if ($differences > 0) {
            $this->error($differences.' table(s) differ from '.$path.'.');

            return self::FAILURE;
        }
        $this->info('Every Golden Path table matches '.$path.'.');

        return self::SUCCESS;
    }
}
