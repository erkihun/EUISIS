<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupStatusService;
use Illuminate\Console\Command;

class BackupList extends Command
{
    protected $signature = 'backup:list {--repo= : Limit to repository 1 or 2}';

    protected $description = 'List sanitized backup inventory (labels, types, times and sizes only)';

    public function handle(BackupStatusService $service): int
    {
        $repo = $this->option('repo');
        if ($repo !== null && ! in_array($repo, ['1', '2'], true)) {
            $this->error('Repository must be 1 or 2.');

            return self::FAILURE;
        }
        $status = $service->status(fresh: true);
        if ($status['infrastructure_status'] !== 'AVAILABLE') {
            $this->error('Backup inventory unavailable: '.$status['infrastructure_status'].' ('.($status['reason_code'] ?? 'UNKNOWN').')');

            return self::FAILURE;
        }
        $rows = [];
        foreach ($status['repositories'] as $repository) {
            if ($repo !== null && $repository['id'] !== (int) $repo) {
                continue;
            }
            foreach ($repository['backups'] as $backup) {
                $rows[] = [$repository['id'], $backup['reference'], $backup['type'], gmdate('Y-m-d H:i:s', $backup['completed_at']).' UTC',
                    number_format($backup['size'] / 1048576, 1).' MiB'];
            }
        }
        if ($rows === []) {
            $this->warn('BACKUP_NOT_FOUND: the repository answered but holds no valid backup.');

            return self::FAILURE;
        }
        $this->table(['Repository', 'Backup label', 'Type', 'Completed', 'Size'], $rows);

        return self::SUCCESS;
    }
}
