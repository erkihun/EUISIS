<?php

declare(strict_types=1);

namespace App\Console\Commands\Core;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;

/**
 * A fingerprint of the Golden Path data for migration and cutover validation:
 * per table, the row count and a SHA-256 over the identifying columns of every
 * row in primary-key order. Two snapshots taken from the same data on any
 * database engine are identical; any added, missing or changed identifier,
 * status or pointer shows up as a different checksum.
 *
 * Read-only. Prints identifiers' hashes only, never values.
 *
 *   php artisan core:snapshot --output=before.json
 *   php artisan core:snapshot --compare=before.json   (exit 1 on any difference)
 */
class CoreSnapshot extends Command
{
    protected $signature = 'core:snapshot {--output= : Write the snapshot JSON to this file} {--compare= : Compare with a snapshot file and fail on any difference}';

    protected $description = 'Fingerprint the Golden Path tables (counts and checksums) for migration and cutover validation; read-only';

    /** @var array<string, list<string>> table => columns that identify a row and its Golden Path state */
    public const TABLES = [
        'organization_types' => ['id', 'code'],
        'organizations' => ['id', 'code', 'status', 'organization_type_id'],
        'hierarchy_versions' => ['id', 'status'],
        'organization_edges' => ['id', 'hierarchy_version_id', 'parent_organization_id', 'child_organization_id', 'relationship_type'],
        'organization_unit_types' => ['id', 'code'],
        'organization_units' => ['id', 'organization_id', 'parent_unit_id', 'code', 'status'],
        'positions' => ['id', 'organization_id', 'organization_unit_id', 'job_position_code', 'is_active'],
        'position_establishments' => ['id', 'position_id', 'approved_slots', 'status'],
        'position_occupancies' => ['id', 'position_id', 'employee_assignment_id', 'status'],
        'employees' => ['id', 'employee_number', 'status', 'current_assignment_id'],
        'employee_assignments' => ['id', 'employee_id', 'organization_id', 'position_id', 'assignment_status', 'is_current', 'effective_from', 'effective_to'],
        'id_cards' => ['id', 'employee_id', 'card_number', 'public_card_uuid', 'status', 'is_current', 'previous_card_id'],
        'card_requests' => ['id', 'employee_id', 'status'],
        'card_replacements' => ['id', 'old_card_id', 'new_card_id'],
    ];

    private const CHUNK = 2000;

    public function handle(): int
    {
        $snapshot = ['taken_at' => now()->toIso8601String(), 'tables' => []];
        foreach (self::TABLES as $table => $columns) {
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

    /**
     * @param  list<string>  $columns
     * @return array{count: int, sha256: string}
     */
    private function fingerprint(string $table, array $columns): array
    {
        $columns = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn($table, $column)));
        $hash = hash_init('sha256');
        $count = 0;
        $lastId = null;

        // Keyset paging by id: the same order on every engine, constant memory at any size.
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

        return ['count' => $count, 'sha256' => hash_final($hash)];
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

    /** @param array{tables: array<string, array{count: int, sha256: string}|null>} $current */
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
        foreach (self::TABLES as $table => $columns) {
            $before = $previous['tables'][$table] ?? null;
            $after = $current['tables'][$table] ?? null;
            $same = $before == $after;
            $differences += $same ? 0 : 1;
            $rows[] = [$table, $before['count'] ?? '-', $after['count'] ?? '-', $same ? 'same' : 'DIFFERENT'];
        }
        $this->table(['Table', 'Before', 'Now', 'Checksum'], $rows);

        if ($differences > 0) {
            $this->error($differences.' table(s) differ from '.$path.'.');

            return self::FAILURE;
        }
        $this->info('Every Golden Path table matches '.$path.'.');

        return self::SUCCESS;
    }
}
