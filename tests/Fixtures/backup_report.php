<?php

use App\Services\Backup\BackupEvidenceReader;
use App\Services\Backup\BackupStatusService;
use App\Services\Backup\WalArchiveInspector;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/** Shape of `pgbackrest --stanza=euisis --output=json info` (pgBackRest 2.33+) with two repositories. */
function pgbackrestInfo(?array $backups = null, array $repoCodes = [1 => 0, 2 => 0], array $ciphers = [1 => 'aes-256-cbc', 2 => 'aes-256-cbc'], string $stanza = 'euisis'): string
{
    $now = now()->timestamp;
    $backups ??= [['label' => '20260927-010000F', 'type' => 'full', 'stop' => $now - 3600, 'repo' => 1],
        ['label' => '20260927-010000F', 'type' => 'full', 'stop' => $now - 3000, 'repo' => 2]];
    $repos = [];
    foreach ($repoCodes as $key => $code) {
        $repos[] = ['key' => $key, 'cipher' => $ciphers[$key], 'status' => ['code' => $code, 'message' => $code === 0 ? 'ok' : 'other']];
    }

    return json_encode([[
        'name' => $stanza,
        'cipher' => 'mixed',
        'status' => ['code' => 0, 'message' => 'ok', 'lock' => ['backup' => ['held' => false]]],
        'db' => [['id' => 1, 'repo-key' => 1, 'system-id' => 7433000000000000000, 'version' => '18']],
        'repo' => $repos,
        'archive' => [['database' => ['id' => 1, 'repo-key' => 1], 'id' => '18-1', 'min' => '000000010000000000000001', 'max' => '0000000100000000000000A3']],
        'backup' => array_map(fn ($backup) => [
            'label' => $backup['label'], 'type' => $backup['type'], 'prior' => null, 'reference' => null,
            'timestamp' => ['start' => $backup['stop'] - 600, 'stop' => $backup['stop']],
            'info' => ['size' => 1000, 'delta' => 1000, 'repository' => ['size' => 200, 'delta' => 200]],
            'database' => ['id' => 1, 'repo-key' => $backup['repo']], 'error' => $backup['error'] ?? false,
            'annotation' => ['ticket' => 'repo1-cipher-pass=TOP_SECRET'],
        ], $backups),
    ]]);
}

function healthyWal(): array
{
    return ['status' => 'HEALTHY', 'reason_code' => null, 'archive_mode' => 'on', 'last_archived_at' => now()->timestamp - 60,
        'last_failed_at' => null, 'failed_count' => 0, 'backlog' => 0];
}

function healthyEvidence(): array
{
    $now = now()->timestamp;
    $operations = [];
    foreach ([1, 2] as $id) {
        foreach (['VERIFY', 'RESTORE_TEST'] as $type) {
            $operations[] = ['id' => (string) Str::uuid(), 'type' => $type, 'repository' => $id, 'status' => 'SUCCEEDED',
                'started_at' => $now - 1000, 'completed_at' => $now - 500, 'backup_reference' => null, 'backup_size' => null,
                'recovery_target' => null, 'failure_summary' => null];
        }
    }

    return ['status' => 'AVAILABLE', 'reason_code' => null, 'operations' => $operations,
        'controls' => array_fill_keys(BackupStatusService::CONTROLS, true), 'controls_checked_at' => $now, 'capacity' => [1 => 30, 2 => 30]];
}

/** The runner report (scripts/backup/backup.py collect) used by the report driver and evidence reader. */
function healthyBackupReport(): array
{
    $now = now()->timestamp;

    return ['version' => 1, 'observed_at' => $now, 'database' => 'HEALTHY',
        'repositories' => array_map(fn ($id) => ['id' => $id, 'available' => true, 'usage_percent' => 30,
            'backups' => [['reference' => '20260927-010000F', 'type' => 'full', 'completed_at' => $now - 3600, 'size' => 1000]]], [1, 2]),
        'wal' => ['enabled' => true, 'last_archived_at' => $now - 60, 'failed_count' => 0, 'failing' => false, 'backlog' => 0],
        'operations' => healthyEvidence()['operations'], 'controls_checked_at' => $now,
        'controls' => array_fill_keys(BackupStatusService::CONTROLS, true)];
}

function writeBackupReport(array $report): string
{
    $path = tempnam(sys_get_temp_dir(), 'euisis-backup-report');
    file_put_contents($path, json_encode($report));
    config(['backup.report_path' => $path]);

    return $path;
}

/** Healthy pgBackRest driver: fixed processes are faked, WAL and evidence are stubbed. */
function fakeBackupInfrastructure(?string $info = null, ?array $wal = null, ?array $evidence = null, ?array $processes = null): void
{
    config(['backup.enabled' => true, 'backup.driver' => 'pgbackrest', 'backup.cache_seconds' => 0,
        'backup.pgbackrest.binary' => PHP_BINARY, 'backup.pgbackrest.config' => null, 'backup.pgbackrest.run_as' => null,
        'backup.rpo' => 'approved', 'backup.rto' => 'approved']);
    if (config('backup.alert_user_ids') === []) {
        config(['backup.alert_user_ids' => [1]]);
    }
    Process::preventStrayProcesses();
    Process::fake($processes ?? ['* version' => Process::result("pgBackRest 2.54.0\n"), '* info' => Process::result($info ?? pgbackrestInfo())]);
    fakeWal($wal ?? healthyWal());
    fakeEvidence($evidence ?? healthyEvidence());
}

function fakeWal(array $wal): void
{
    $inspector = Mockery::mock(WalArchiveInspector::class)->makePartial();
    $inspector->shouldReceive('inspect')->andReturn($wal);
    app()->instance(WalArchiveInspector::class, $inspector);
}

function fakeEvidence(array $evidence): void
{
    $reader = Mockery::mock(BackupEvidenceReader::class);
    $reader->shouldReceive('read')->andReturn($evidence);
    app()->instance(BackupEvidenceReader::class, $reader);
}
