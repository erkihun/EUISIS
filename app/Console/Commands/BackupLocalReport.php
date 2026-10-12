<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backup\BackupStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Writes the runner report (the file scripts/backup/backup.py publishes on a real deployment) with
 * sample data, so the backup page and backup:health can be exercised on a developer machine that has
 * no pgBackRest. It is never evidence of a backup: it refuses to run in production.
 */
class BackupLocalReport extends Command
{
    protected $signature = 'backup:local-report
        {--scenario=healthy : healthy, stale-backup, failed-verify or repository-down}
        {--path= : Where to write the report; defaults to BACKUP_STATUS_PATH}';

    protected $description = 'Write a sample runner report so the backup page can be tested locally (never in production)';

    private const SCENARIOS = ['healthy', 'stale-backup', 'failed-verify', 'repository-down'];

    public function handle(): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('LOCAL_ONLY: sample reports are never written in production.');

            return self::FAILURE;
        }
        $scenario = (string) $this->option('scenario');
        if (! in_array($scenario, self::SCENARIOS, true)) {
            $this->error('UNKNOWN_SCENARIO: use one of '.implode(', ', self::SCENARIOS).'.');

            return self::FAILURE;
        }
        $path = (string) ($this->option('path') ?: config('backup.report_path'));
        if ($path === '' || str_contains($path, '://')) {
            $this->error('INVALID_PATH: set BACKUP_STATUS_PATH or pass --path.');

            return self::FAILURE;
        }
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error("CANNOT_CREATE_DIRECTORY: {$directory}");

            return self::FAILURE;
        }

        // Atomic like the runner: write beside the target, then rename over it.
        $temporary = $directory.DIRECTORY_SEPARATOR.'.status.'.Str::random(8).'.tmp';
        $json = json_encode($this->report($scenario, time()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($temporary, $json) === false || ! @rename($temporary, $path)) {
            @unlink($temporary);
            $this->error("CANNOT_WRITE_REPORT: {$path}");

            return self::FAILURE;
        }

        $this->info("Sample report ({$scenario}) written to {$path}.");
        $this->line('Read it with BACKUP_STATUS_ENABLED=true and BACKUP_DRIVER=report, then: php artisan backup:health');

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function report(string $scenario, int $now): array
    {
        // Thresholds (config/backup.php): a backup older than critical_hours (48 h) is CRITICAL, a full
        // older than full_stale_days (8 d) a WARNING, verify and restore tests have their own windows.
        $latestAt = $now - ($scenario === 'stale-backup' ? 3 * 86400 : 6 * 3600);
        $fullAt = $latestAt - 2 * 86400;
        $full = gmdate('Ymd-His', $fullAt).'F';
        $incremental = $full.'_'.gmdate('Ymd-His', $latestAt).'I';
        $fullSize = 1_288_490_188;
        $incrementalSize = 41_943_040;

        $repositories = [];
        $operations = [];
        foreach (BackupStatusService::REPOSITORIES as $id) {
            $repositories[] = [
                'id' => $id,
                'available' => ! ($scenario === 'repository-down' && $id === 2),
                'usage_percent' => $id === 1 ? 31 : 27,
                'backups' => [
                    ['reference' => $full, 'type' => 'full', 'completed_at' => $fullAt, 'size' => $fullSize],
                    ['reference' => $incremental, 'type' => 'incr', 'completed_at' => $latestAt, 'size' => $incrementalSize],
                ],
            ];
            $operations[] = $this->operation('FULL_BACKUP', $id, $fullAt - 1500, $fullAt, $full, $fullSize);
            $operations[] = $this->operation('INCREMENTAL_BACKUP', $id, $latestAt - 240, $latestAt, $incremental, $incrementalSize);
            $operations[] = $this->operation('VERIFY', $id, $latestAt + 120, $latestAt + 900, $incremental, null,
                $scenario === 'failed-verify' && $id === 1 ? 'FAILED' : 'SUCCEEDED');
            $restoreTest = $now - 5 * 86400;
            $operations[] = $this->operation('RESTORE_TEST', $id, $restoreTest, $restoreTest + 2700, $full, null, 'SUCCEEDED', $restoreTest - 600);
        }

        return [
            'version' => 1,
            'observed_at' => $now,
            'database' => 'HEALTHY',
            'repositories' => $repositories,
            'wal' => ['enabled' => true, 'last_archived_at' => $now - 90, 'failed_count' => 0, 'failing' => false, 'backlog' => 0],
            'operations' => $operations,
            'controls' => array_fill_keys(BackupStatusService::CONTROLS, true),
            'controls_checked_at' => $now - 86400,
            'sample' => 'Written by backup:local-report for local testing; not evidence of any real backup.',
        ];
    }

    /** @return array<string, mixed> */
    private function operation(string $type, int $repository, int $startedAt, int $completedAt, ?string $reference, ?int $size, string $status = 'SUCCEEDED', ?int $recoveryTarget = null): array
    {
        return [
            'id' => (string) Str::uuid(),
            'type' => $type,
            'repository' => $repository,
            'status' => $status,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'backup_reference' => $reference,
            'backup_size' => $size,
            'recovery_target' => $recoveryTarget,
            'failure_summary' => $status === 'FAILED' ? 'Sample failure written for local testing.' : null,
        ];
    }
}
