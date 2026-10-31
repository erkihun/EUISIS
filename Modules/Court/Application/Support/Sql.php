<?php

declare(strict_types=1);

namespace Modules\Court\Application\Support;

use Illuminate\Support\Facades\DB;

/**
 * SQL that court-ms wrote for MySQL alone, in the dialect of the connection
 * (EUISIS runs PostgreSQL, MySQL in production builds and SQLite in tests).
 */
final class Sql
{
    /** `YYYY-MM` of a date column (MySQL DATE_FORMAT(column, '%Y-%m')). */
    public static function yearMonth(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            'sqlite' => "strftime('%Y-%m', {$column})",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }

    /** The number between the first and second `/` of a case number (LAB/00001/26 gives 1). */
    public static function middleSegmentNumber(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "CAST(NULLIF(split_part({$column}, '/', 2), '') AS INTEGER)",
            'sqlite' => "CAST(substr({$column}, instr({$column}, '/') + 1, instr(substr({$column}, instr({$column}, '/') + 1), '/') - 1) AS INTEGER)",
            default => "CAST(SUBSTRING_INDEX(SUBSTRING_INDEX({$column}, '/', 2), '/', -1) AS UNSIGNED)",
        };
    }
}
