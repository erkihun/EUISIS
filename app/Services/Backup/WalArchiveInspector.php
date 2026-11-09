<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * WAL archiving health read directly from the application's PostgreSQL connection: archive_mode and
 * pg_stat_archiver are readable by any role. The ready-segment backlog needs pg_monitor (or an explicit
 * EXECUTE grant on pg_ls_archive_statusdir) and is reported as unknown without it.
 */
class WalArchiveInspector
{
    public function inspect(): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return $this->evaluate(null, 'DATABASE_NOT_POSTGRESQL');
        }
        try {
            $row = (array) DB::selectOne("select current_setting('archive_mode') as archive_mode,
                extract(epoch from last_archived_time)::bigint as last_archived_at, failed_count,
                extract(epoch from last_failed_time)::bigint as last_failed_at, extract(epoch from now())::bigint as now,
                has_function_privilege('pg_ls_archive_statusdir()', 'EXECUTE') as can_read_backlog
                from pg_stat_archiver");
            $row['backlog'] = $row['can_read_backlog']
                ? (int) DB::selectOne("select count(*) as ready from pg_ls_archive_statusdir() where name like '%.ready'")->ready
                : null;
        } catch (Throwable) {
            return $this->evaluate(null, 'DATABASE_UNAVAILABLE');
        }

        return $this->evaluate($row);
    }

    /** @param  array{archive_mode: string, last_archived_at: ?int, failed_count: int, last_failed_at: ?int, now: int, backlog: ?int}|null  $row */
    public function evaluate(?array $row, ?string $unavailable = null): array
    {
        $wal = ['status' => 'UNKNOWN', 'reason_code' => $unavailable, 'archive_mode' => null, 'last_archived_at' => null,
            'last_failed_at' => null, 'failed_count' => null, 'backlog' => null];
        if ($row === null) {
            return $wal;
        }
        $last = $row['last_archived_at'] === null ? null : (int) $row['last_archived_at'];
        $failed = $row['last_failed_at'] === null ? null : (int) $row['last_failed_at'];
        $backlog = $row['backlog'] === null ? null : (int) $row['backlog'];
        $wal = [...$wal, 'archive_mode' => (string) $row['archive_mode'], 'last_archived_at' => $last, 'last_failed_at' => $failed,
            'failed_count' => (int) $row['failed_count'], 'backlog' => $backlog];
        [$wal['status'], $wal['reason_code']] = match (true) {
            ! in_array($wal['archive_mode'], ['on', 'always'], true) => config('backup.pitr_required') ? ['WAL_ARCHIVE_UNHEALTHY', 'ARCHIVE_MODE_OFF'] : ['NOT_REQUIRED', null],
            $failed !== null && ($last === null || $failed > $last) => ['WAL_ARCHIVE_UNHEALTHY', 'ARCHIVE_FAILING'],
            $last === null => ['WAL_ARCHIVE_UNHEALTHY', 'NO_WAL_ARCHIVED'],
            $backlog !== null && $backlog > config('backup.wal_backlog_segments') => ['WAL_ARCHIVE_UNHEALTHY', 'ARCHIVE_BACKLOG'],
            // An idle cluster also stops archiving, so lag alone is a warning, not proof of failure.
            (int) $row['now'] - $last > config('backup.wal_lag_seconds') => ['WARNING', 'ARCHIVE_LAG'],
            default => ['HEALTHY', null],
        };

        return $wal;
    }
}
