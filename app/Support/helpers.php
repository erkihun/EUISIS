<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

if (! function_exists('ci_like_operator')) {
    /**
     * Case-insensitive LIKE operator for the active database driver.
     *
     * Postgres LIKE is case-sensitive, so it needs ILIKE. MySQL/MariaDB and
     * SQLite LIKE are already case-insensitive for typical text, so plain LIKE
     * is correct there (and ILIKE would be a syntax error on SQLite, which the
     * test suite uses).
     */
    function ci_like_operator(?string $connection = null): string
    {
        return DB::connection($connection)->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }
}
