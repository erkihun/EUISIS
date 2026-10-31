<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Text expressions that read the same on every driver the application runs
 * on (PostgreSQL in development, MySQL/MariaDB on the live server, SQLite in
 * tests). MySQL has no VARCHAR in CAST and treats || as logical OR, so raw
 * SQL written against PostgreSQL either fails there or silently yields 0.
 */
final class SqlText
{
    /** An id column (uuid, char(36) or text) as a plain string. */
    public static function idText(string $column, ?string $driver = null): string
    {
        if (preg_match('/^[a-z_][a-z0-9_.]*$/i', $column) !== 1) {
            throw new InvalidArgumentException("Unsafe column name [{$column}].");
        }

        return match ($driver ?? self::driver()) {
            'pgsql' => "CAST({$column} AS VARCHAR(64))",
            'mysql', 'mariadb' => "CAST({$column} AS CHAR(64))",
            default => "CAST({$column} AS TEXT)",
        };
    }

    /**
     * The concatenation of already-safe SQL fragments, in order.
     *
     * @param  list<string>  $parts
     */
    public static function concat(array $parts, ?string $driver = null): string
    {
        return match ($driver ?? self::driver()) {
            'mysql', 'mariadb' => 'CONCAT('.implode(', ', $parts).')',
            default => '('.implode(' || ', $parts).')',
        };
    }

    private static function driver(): string
    {
        return DB::connection()->getDriverName();
    }
}
