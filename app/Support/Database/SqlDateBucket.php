<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A GROUP BY expression that truncates a timestamp column to the start of
 * its day, ISO week or month, on every driver the application runs on
 * (PostgreSQL in development, MySQL/MariaDB on the live server, SQLite in
 * tests). The value is always a 'Y-m-d' string, so callers never parse
 * driver-specific formats.
 */
final class SqlDateBucket
{
    public const DAY = 'day';

    public const WEEK = 'week';

    public const MONTH = 'month';

    public static function expression(string $column, string $unit): string
    {
        if (! in_array($unit, [self::DAY, self::WEEK, self::MONTH], true)) {
            throw new InvalidArgumentException("Unknown date bucket unit [{$unit}].");
        }

        if (preg_match('/^[a-z_][a-z0-9_.]*$/i', $column) !== 1) {
            throw new InvalidArgumentException("Unsafe column name [{$column}].");
        }

        return match (DB::connection()->getDriverName()) {
            'pgsql' => match ($unit) {
                self::DAY => "TO_CHAR(DATE_TRUNC('day', {$column}), 'YYYY-MM-DD')",
                self::WEEK => "TO_CHAR(DATE_TRUNC('week', {$column}), 'YYYY-MM-DD')",
                self::MONTH => "TO_CHAR(DATE_TRUNC('month', {$column}), 'YYYY-MM-DD')",
            },
            'mysql', 'mariadb' => match ($unit) {
                self::DAY => "DATE_FORMAT({$column}, '%Y-%m-%d')",
                // Monday of the ISO week.
                self::WEEK => "DATE_FORMAT(DATE_SUB({$column}, INTERVAL WEEKDAY({$column}) DAY), '%Y-%m-%d')",
                self::MONTH => "DATE_FORMAT({$column}, '%Y-%m-01')",
            },
            default => match ($unit) {
                self::DAY => "strftime('%Y-%m-%d', {$column})",
                // SQLite: %w is 0 for Sunday; step back to Monday.
                self::WEEK => "strftime('%Y-%m-%d', {$column}, '-' || ((CAST(strftime('%w', {$column}) AS INTEGER) + 6) % 7) || ' days')",
                self::MONTH => "strftime('%Y-%m-01', {$column})",
            },
        };
    }

    /** Day buckets up to a month, weeks up to about four months, then months. */
    public static function unitForDays(int $days): string
    {
        return match (true) {
            $days <= 31 => self::DAY,
            $days <= 120 => self::WEEK,
            default => self::MONTH,
        };
    }
}
