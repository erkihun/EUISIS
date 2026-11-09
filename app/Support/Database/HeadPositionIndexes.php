<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The database backstop for "one head position per unit" and "one head
 * position per organization" (ADR 0025). The position actions check the rule
 * under a row lock; the index is what makes two requests racing on the same
 * unit produce one head.
 *
 * PostgreSQL and SQLite take a partial unique index. MySQL/MariaDB have none:
 * a VIRTUAL generated column holds the key only for rows the condition covers
 * (NULL otherwise, and NULLs never collide) and a plain unique index goes on
 * it — VIRTUAL, not STORED, because MySQL refuses a STORED generated column
 * whose base columns carry a cascading foreign key (error 1215).
 *
 * Existing data is never modified. Where duplicates already exist the index
 * is left out and reported, so a deployment is not stopped by legacy data;
 * `reporting:diagnose --preflight` lists the duplicates and
 * `reporting:ensure-head-indexes` adds the index once they are fixed.
 */
final class HeadPositionIndexes
{
    public const UNIT = 'positions_one_head_per_unit';

    public const ORGANIZATION = 'positions_one_head_per_organization';

    /** @return array<string, array{columns: string, where: string, generated: string}> */
    public static function constraints(): array
    {
        $head = DB::getDriverName() === 'pgsql' ? 'is_unit_head' : 'is_unit_head = 1';

        return [
            self::UNIT => [
                'columns' => 'organization_unit_id',
                'where' => "{$head} AND deleted_at IS NULL AND organization_unit_id IS NOT NULL",
                'generated' => 'unit_head_key',
            ],
            self::ORGANIZATION => [
                'columns' => 'organization_id',
                'where' => "{$head} AND deleted_at IS NULL AND organization_unit_id IS NULL",
                'generated' => 'organization_head_key',
            ],
        ];
    }

    /**
     * Rows that would violate each index, as index name => duplicate count.
     *
     * @return array<string, int>
     */
    public static function violations(): array
    {
        if (! Schema::hasTable('positions') || ! Schema::hasColumn('positions', 'is_unit_head')) {
            return [];
        }
        $violations = [];
        foreach (self::constraints() as $name => $c) {
            $duplicates = DB::selectOne(
                "SELECT COUNT(*) AS n FROM (SELECT {$c['columns']} FROM positions WHERE {$c['where']} GROUP BY {$c['columns']} HAVING COUNT(*) > 1) d"
            )->n;
            if ((int) $duplicates > 0) {
                $violations[$name] = (int) $duplicates;
            }
        }

        return $violations;
    }

    /** @return array<string, bool> index name => present */
    public static function status(): array
    {
        $status = [];
        foreach (array_keys(self::constraints()) as $name) {
            $status[$name] = Schema::hasTable('positions') && Schema::hasIndex('positions', $name);
        }

        return $status;
    }

    /**
     * Add every index whose data allows it. Returns the indexes added and
     * those skipped with their duplicate counts.
     *
     * @return array{added: list<string>, skipped: array<string, int>, present: list<string>}
     */
    public static function ensure(): array
    {
        $result = ['added' => [], 'skipped' => [], 'present' => []];
        if (! Schema::hasTable('positions') || ! Schema::hasColumn('positions', 'is_unit_head')) {
            return $result;
        }
        $violations = self::violations();
        foreach (self::constraints() as $name => $c) {
            if (Schema::hasIndex('positions', $name)) {
                $result['present'][] = $name;

                continue;
            }
            if (isset($violations[$name])) {
                $result['skipped'][$name] = $violations[$name];

                continue;
            }
            if (! self::mysql()) {
                DB::statement("CREATE UNIQUE INDEX {$name} ON positions ({$c['columns']}) WHERE {$c['where']}");
            } else {
                if (! Schema::hasColumn('positions', $c['generated'])) {
                    DB::statement("ALTER TABLE positions ADD COLUMN {$c['generated']} CHAR(36) GENERATED ALWAYS AS (CASE WHEN {$c['where']} THEN {$c['columns']} ELSE NULL END) VIRTUAL");
                }
                DB::statement("CREATE UNIQUE INDEX {$name} ON positions ({$c['generated']})");
            }
            $result['added'][] = $name;
        }

        return $result;
    }

    public static function drop(): void
    {
        if (! Schema::hasTable('positions')) {
            return;
        }
        foreach (self::constraints() as $name => $c) {
            if (Schema::hasIndex('positions', $name)) {
                Schema::table('positions', fn ($table) => $table->dropIndex($name));
            }
            if (self::mysql() && Schema::hasColumn('positions', $c['generated'])) {
                Schema::table('positions', fn ($table) => $table->dropColumn($c['generated']));
            }
        }
    }

    /**
     * Whether a thrown database error is one of these indexes refusing a
     * second head. PostgreSQL and MySQL name the index; SQLite names the
     * indexed column (positions.organization_unit_id / positions.organization_id),
     * which no other unique index on positions covers.
     */
    public static function isViolation(\Throwable $e): bool
    {
        $message = $e->getMessage();
        $markers = [...array_keys(self::constraints()), 'unit_head_key', 'organization_head_key',
            'positions.organization_unit_id', 'positions.organization_id'];
        foreach ($markers as $marker) {
            if (str_contains($message, $marker)) {
                return true;
            }
        }

        return false;
    }

    private static function mysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
}
