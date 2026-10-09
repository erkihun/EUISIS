<?php

namespace App\Services\Backup\Infrastructure;

use App\Services\Backup\BackupReportException;
use App\Services\Backup\BackupReportReader;
use Illuminate\Support\Facades\Validator;

/**
 * For application hosts without pgBackRest access: reads the repository section of the report the
 * ops runner publishes atomically (scripts/backup/backup.py collect). PHP never touches repositories.
 */
final class ReportBackupAdapter implements BackupInfrastructureAdapter
{
    public function __construct(private BackupReportReader $reader) {}

    public function driver(): string
    {
        return 'report';
    }

    public function isConfigured(): bool
    {
        return (bool) config('backup.enabled');
    }

    public function version(): ?string
    {
        return null;
    }

    public function inspect(): InfrastructureSnapshot
    {
        try {
            $raw = $this->reader->read();
        } catch (BackupReportException $e) {
            return InfrastructureSnapshot::failure($e->reasonCode, $this->driver(), binary: 'NOT_CHECKED', diagnostics: ['report' => $e->reasonCode]);
        }
        $validator = Validator::make($raw, [
            'version' => ['required', 'integer', 'in:1'],
            'observed_at' => ['required', 'integer', 'min:1'],
            'repositories' => ['required', 'array', 'size:2'],
            'repositories.*.id' => ['required', 'integer', 'in:1,2', 'distinct'],
            'repositories.*.available' => ['required', 'boolean'],
            'repositories.*.backups' => ['present', 'array', 'max:500'],
            'repositories.*.backups.*.reference' => ['required', 'regex:'.PgBackRestInfoParser::LABEL],
            'repositories.*.backups.*.type' => ['required', 'in:full,diff,incr'],
            'repositories.*.backups.*.completed_at' => ['required', 'integer', 'min:1'],
            'repositories.*.backups.*.size' => ['required', 'integer', 'min:0'],
            'repositories.*.usage_percent' => ['nullable', 'numeric', 'between:0,100'],
        ]);
        if ($validator->fails()) {
            return InfrastructureSnapshot::failure('INVALID_REPORT', $this->driver(), binary: 'NOT_CHECKED', diagnostics: ['report' => 'SCHEMA_INVALID']);
        }
        $observed = $raw['observed_at'];
        if ($observed > time() + 60 || time() - $observed > config('backup.report_stale_minutes') * 60) {
            return new InfrastructureSnapshot(InfrastructureSnapshot::UNKNOWN, 'REPORT_STALE', $this->driver(), binary: 'NOT_CHECKED',
                diagnostics: ['report' => 'STALE_OR_FUTURE'], checkedAt: time());
        }
        $repositories = [];
        foreach ($raw['repositories'] as $repo) {
            $backups = [];
            foreach ($repo['backups'] as $backup) {
                if ($backup['completed_at'] > $observed + 60) {
                    return InfrastructureSnapshot::failure('INVALID_REPORT', $this->driver(), binary: 'NOT_CHECKED', diagnostics: ['report' => 'FUTURE_BACKUP']);
                }
                $backups[] = array_intersect_key($backup, array_flip(['reference', 'type', 'completed_at', 'size']));
            }
            usort($backups, fn ($a, $b) => $b['completed_at'] <=> $a['completed_at']);
            $repositories[] = ['id' => $repo['id'], 'status' => $repo['available'] ? 'AVAILABLE' : 'REPOSITORY_UNAVAILABLE', 'encrypted' => null,
                'backups' => $backups, 'errored_backups' => 0, 'usage_percent' => $repo['usage_percent'] ?? null, 'wal_max' => null];
        }
        usort($repositories, fn ($a, $b) => $a['id'] <=> $b['id']);

        return new InfrastructureSnapshot(InfrastructureSnapshot::AVAILABLE, null, $this->driver(), binary: 'NOT_CHECKED',
            repositories: $repositories, diagnostics: ['report' => 'OK'], checkedAt: $observed);
    }

    public function check(): array
    {
        // The end-to-end check runs on the database host: backup.py check --repo=N.
        return ['status' => 'NOT_RUN', 'reason_code' => 'NOT_SUPPORTED_BY_REPORT_DRIVER', 'diagnostics' => []];
    }
}
