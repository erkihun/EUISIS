<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

use Illuminate\Support\Facades\DB;

/**
 * The few expressions that differ between PostgreSQL, MySQL/MariaDB (the live
 * server) and SQLite (the test suite). Everything else the Situation Room runs
 * is portable SQL: COUNT, SUM(CASE ...), GROUP BY, DATE().
 */
final class SituationSql
{
    /** Elapsed days between two timestamp columns, fractional. */
    public static function daysBetween(string $from, string $to): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "(EXTRACT(EPOCH FROM ({$to} - {$from})) / 86400.0)",
            'mysql', 'mariadb' => "(TIMESTAMPDIFF(SECOND, {$from}, {$to}) / 86400.0)",
            default => "(julianday({$to}) - julianday({$from}))",
        };
    }

    /** The calendar day of a timestamp column, as Y-m-d. */
    public static function day(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "TO_CHAR({$column}, 'YYYY-MM-DD')",
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '%Y-%m-%d')",
            default => "strftime('%Y-%m-%d', {$column})",
        };
    }
}
