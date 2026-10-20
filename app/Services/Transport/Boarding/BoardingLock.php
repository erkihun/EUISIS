<?php

declare(strict_types=1);

namespace App\Services\Transport\Boarding;

use Illuminate\Support\Facades\DB;

/**
 * Serialises the boarding decision per employee inside the current
 * transaction, so two scanners reading the same card at the same moment on
 * two trips reach the duplicate check one after the other. On PostgreSQL
 * this is a transaction-scoped advisory lock; the unique index on
 * (trip, employee) remains the backstop for the same trip. SQLite (the test
 * engine) serialises writers itself.
 */
final class BoardingLock
{
    public function forEmployee(string $employeeId): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['transport_boarding:'.$employeeId]);
    }
}
