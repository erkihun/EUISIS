<?php

use App\Services\Backup\WalArchiveInspector;
use Tests\TestCase;

uses(TestCase::class);

function walRow(array $overrides = []): array
{
    $now = 1_800_000_000;

    return [...['archive_mode' => 'on', 'last_archived_at' => $now - 60, 'failed_count' => 3, 'last_failed_at' => $now - 86400, 'now' => $now, 'backlog' => 0], ...$overrides];
}

it('evaluates WAL archiving from pg_stat_archiver', function ($overrides, $status, $reason) {
    expect((new WalArchiveInspector)->evaluate(walRow($overrides)))->status->toBe($status)->reason_code->toBe($reason);
})->with([
    'healthy, earlier failure resolved' => [[], 'HEALTHY', null],
    'archive_mode off' => [['archive_mode' => 'off'], 'WAL_ARCHIVE_UNHEALTHY', 'ARCHIVE_MODE_OFF'],
    'latest attempt failed' => [['last_failed_at' => 1_800_000_000 - 10], 'WAL_ARCHIVE_UNHEALTHY', 'ARCHIVE_FAILING'],
    'never archived' => [['last_archived_at' => null, 'last_failed_at' => null], 'WAL_ARCHIVE_UNHEALTHY', 'NO_WAL_ARCHIVED'],
    'backlog above threshold' => [['backlog' => 17], 'WAL_ARCHIVE_UNHEALTHY', 'ARCHIVE_BACKLOG'],
    'lag only (possibly idle)' => [['last_archived_at' => 1_800_000_000 - 601], 'WARNING', 'ARCHIVE_LAG'],
    'backlog not readable' => [['backlog' => null], 'HEALTHY', null],
]);

it('does not require archiving when PITR is not required', function () {
    config(['backup.pitr_required' => false]);
    expect((new WalArchiveInspector)->evaluate(walRow(['archive_mode' => 'off'])))->status->toBe('NOT_REQUIRED');
});

it('is UNKNOWN, never healthy, when the database cannot be inspected', function () {
    expect((new WalArchiveInspector)->inspect())->status->toBe('UNKNOWN')->reason_code->toBe('DATABASE_NOT_POSTGRESQL')
        ->and((new WalArchiveInspector)->evaluate(null, 'DATABASE_UNAVAILABLE'))->status->toBe('UNKNOWN');
});
