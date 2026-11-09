<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Support;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * Serialises the "next number" reads (the highest case number, the count of a
 * case's responses) inside a transaction. MySQL locks the rows read
 * (FOR UPDATE); PostgreSQL refuses FOR UPDATE beside MAX() or COUNT(), so it
 * takes a transaction-scoped advisory lock on the sequence instead; SQLite
 * (tests) locks the whole database on write anyway.
 */
final class AggregateLock
{
    /**
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function apply(Builder $query, string $sequence): Builder
    {
        $connection = $query->getConnection();

        return match ($connection->getDriverName()) {
            'pgsql' => tap($query, fn () => $connection->select('select pg_advisory_xact_lock(?)', [crc32('court:'.$sequence)])),
            'mysql', 'mariadb' => $query->lockForUpdate(),
            default => $query,
        };
    }
}
