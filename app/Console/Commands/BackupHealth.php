<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupMonitor;
use App\Services\Backup\BackupStatusService;
use App\Services\Backup\Infrastructure\BackupInfrastructureAdapter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class BackupHealth extends Command
{
    protected $signature = 'backup:health
        {--monitor : Import the operation journal and notify configured operators}
        {--check : Also run pgbackrest check (operator action; archives a WAL segment)}
        {--json : Print the full sanitized status document}';

    protected $description = 'Diagnose backup health from a fresh probe (read-only; -v adds sanitized process diagnostics)';

    public function handle(BackupStatusService $service, BackupMonitor $monitor, BackupInfrastructureAdapter $adapter): int
    {
        $status = $service->status(fresh: true);
        if ($this->option('monitor')) {
            try {
                Cache::lock('backup.monitor', 300)->block(1, fn () => $monitor->sync($status));
            } catch (Throwable) {
                $this->error('BACKUP_MONITOR_FAILED: inspect restricted operator logs.');

                return self::FAILURE;
            }
        }
        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->summary($status);
        }
        if ($this->option('check')) {
            $this->warn('pgbackrest check forces a WAL switch and archives a segment.');
            $check = $adapter->check();
            $this->line('pgBackRest check: '.$check['status'].($check['reason_code'] ? ' ('.$check['reason_code'].')' : ''));
            if ($this->output->isVerbose()) {
                $this->diagnostics($check['diagnostics']);
            }
        }

        return $status['overall_status'] === 'HEALTHY' ? self::SUCCESS : self::FAILURE;
    }

    private function summary(array $status): void
    {
        $at = fn (?int $time) => $time ? gmdate('Y-m-d H:i', $time).' UTC ('.$this->age(time() - $time).' ago)' : 'UNKNOWN';
        $components = $status['components'];
        $this->line('Environment: '.$status['environment'].($status['enforced'] ? ' (production rules enforced)' : ''));
        $this->line('Backup Integration: '.(config('backup.enabled') ? 'ENABLED' : 'DISABLED').' · driver '.$status['driver']);
        if ($status['driver'] === 'pgbackrest') {
            $this->line('pgBackRest Binary: '.str_replace('_', ' ', $status['binary'] ?? 'NOT CHECKED').($status['version'] ? ' · version '.$status['version'] : ''));
            $this->line('Stanza: '.($status['stanza'] ?? 'INVALID'));
        }
        $this->line('Infrastructure: '.$this->state($components['infrastructure']));
        $this->line('Repository: '.$this->state($components['repository']));
        foreach ($status['repositories'] as $repo) {
            $this->line("  {$repo['id']} ({$repo['name']}): {$repo['status']} · backup {$repo['backup_status']} · latest ".$at($repo['latest']['completed_at'] ?? null)
                .' · encrypted '.($repo['encrypted'] === null ? 'unknown' : ($repo['encrypted'] ? 'yes' : 'NO'))
                .' · usage '.($repo['usage_percent'] === null ? 'unknown' : $repo['usage_percent'].'%'));
        }
        $this->line('Latest Backup: '.$this->state($components['backup']).' · '.$at($status['latest_backup_at']).' · full '.$at($status['latest_full_backup_at']));
        $wal = $components['wal'];
        $this->line('WAL Archive: '.$this->state($wal).' · archive_mode '.($wal['archive_mode'] ?? 'unknown').' · last archived '.$at($wal['last_archived_at'] ?? null)
            .' · backlog '.($wal['backlog'] ?? 'unknown'));
        $this->line('Verification: '.$this->state($components['verification']).' · last passed '.$at($status['latest_verified_at']));
        $this->line('Restore Test: '.$this->state($components['restore_test']).' · last passed '.$at($status['latest_restore_test_at']));
        $this->newLine();
        $this->line('Overall Status: '.$status['overall_status']);
        $this->line('Reason Code: '.($status['reason_code'] ?? 'NONE'));
        $this->line('Message: '.$status['message']);
        $this->line('Readiness: '.$status['readiness'].' · Production Blocker: '.($status['production_blocker'] ? 'YES' : ($status['enforced'] ? 'NO' : 'NO (not enforced in this environment)')));
        foreach ($status['issues'] as $issue) {
            $this->warn("  - [{$issue['severity']}] {$issue['code']}");
        }
        if ($this->output->isVerbose()) {
            $this->diagnostics($status['diagnostics']);
        }
    }

    /** Exit code, output presence, tool error number and parser result only. */
    private function diagnostics(array $diagnostics): void
    {
        $this->line('Diagnostics:');
        foreach ($diagnostics as $key => $value) {
            $this->line('  '.$key.': '.(is_bool($value) ? ($value ? 'yes' : 'no') : ($value ?? 'n/a')));
        }
    }

    private function state(array $component): string
    {
        return $component['status'].($component['reason_code'] ? ' ('.$component['reason_code'].')' : '');
    }

    private function age(int $seconds): string
    {
        return $seconds >= 86400 ? round($seconds / 86400, 1).' d' : round($seconds / 3600, 1).' h';
    }
}
