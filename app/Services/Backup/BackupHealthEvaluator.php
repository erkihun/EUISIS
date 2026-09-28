<?php

namespace App\Services\Backup;

use App\Services\Backup\Infrastructure\InfrastructureSnapshot;
use Closure;

/**
 * Pure status rules: probe results plus policy in, BackupHealthStatus out. Issue severities:
 * CRITICAL and WARNING drive the health state; READINESS marks go-live evidence gaps that block
 * production readiness without implying the running system has failed.
 */
final class BackupHealthEvaluator
{
    private const BACKUP_RANK = ['HEALTHY' => 0, 'WARNING' => 1, 'CRITICAL' => 2, 'BACKUP_NOT_FOUND' => 3];

    private const EVIDENCE_RANK = ['PASSED' => 0, 'OVERDUE' => 1, 'NOT_RECORDED' => 2, 'UNKNOWN' => 3, 'FAILED' => 4];

    /** @param  list<array>|null  $history  imported operations, or null when the history table is unavailable */
    public function evaluate(InfrastructureSnapshot $infra, array $wal, array $evidence, ?array $history, bool $enforced, int $now): BackupHealthStatus
    {
        $issues = [];
        $add = function (string $code, string $reason, string $severity) use (&$issues): void {
            $issues[$code] = ['code' => $code, 'reason' => $reason, 'severity' => $severity];
        };
        $available = $infra->status === InfrastructureSnapshot::AVAILABLE;
        if (! $available) {
            $reason = $infra->reasonCode ?? 'UNKNOWN';
            $add($reason, $reason, $enforced ? 'CRITICAL' : 'READINESS');
        }
        if ($history === null) {
            $add('HISTORY_UNAVAILABLE', 'HISTORY_UNAVAILABLE', 'WARNING');
        }

        // Verification and restore tests come from recorded operations, not from the backup tool.
        $operations = collect($history ?? [])->keyBy('id')->merge(collect($evidence['operations'])->keyBy('id'))->sortByDesc('started_at')->values();
        $evidenceStates = ['VERIFY' => [], 'RESTORE_TEST' => []];
        $lastPassed = ['VERIFY' => [], 'RESTORE_TEST' => []];
        foreach (BackupStatusService::REPOSITORIES as $id) {
            $repoOps = $operations->where('repository', $id);
            foreach (['VERIFY' => 'verify_stale_days', 'RESTORE_TEST' => 'restore_test_days'] as $type => $setting) {
                $items = $repoOps->where('type', $type);
                $latest = $items->first();
                $passed = $items->firstWhere('status', 'SUCCEEDED');
                $state = match (true) {
                    $history === null && $items->isEmpty() => 'UNKNOWN',
                    $latest !== null && $this->failedOrStuck($latest, $now) => 'FAILED',
                    $passed === null => 'NOT_RECORDED',
                    $now - $passed['completed_at'] > config("backup.{$setting}") * 86400 => 'OVERDUE',
                    default => 'PASSED',
                };
                $evidenceStates[$type][$id] = $state;
                $lastPassed[$type][$id] = $passed['completed_at'] ?? null;
                if ($state !== 'PASSED') {
                    $reason = $type.'_'.$state;
                    $add("REPOSITORY_{$id}_{$reason}", $reason, $state === 'FAILED' ? 'CRITICAL' : 'WARNING');
                }
            }
            foreach (['FULL_BACKUP', 'DIFFERENTIAL_BACKUP', 'INCREMENTAL_BACKUP', 'RETENTION_CLEANUP'] as $type) {
                $latest = $repoOps->firstWhere('type', $type);
                if ($latest !== null && $this->failedOrStuck($latest, $now)) {
                    $add("REPOSITORY_{$id}_{$type}_FAILED", 'OPERATION_FAILED', 'CRITICAL');
                }
            }
        }

        $repositories = [];
        foreach ($available ? $infra->repositories : [] as $repo) {
            $repositories[] = [...$this->repository($repo, $evidence['capacity'] ?? [], $now, $add),
                'last_verified_at' => $lastPassed['VERIFY'][$repo['id']] ?? null, 'verify_status' => $evidenceStates['VERIFY'][$repo['id']] ?? 'UNKNOWN',
                'last_restore_test_at' => $lastPassed['RESTORE_TEST'][$repo['id']] ?? null, 'restore_test_status' => $evidenceStates['RESTORE_TEST'][$repo['id']] ?? 'UNKNOWN'];
        }

        match ($wal['status']) {
            'WAL_ARCHIVE_UNHEALTHY' => $add('WAL_ARCHIVE_UNHEALTHY', 'WAL_ARCHIVE_UNHEALTHY', config('backup.pitr_required') ? 'CRITICAL' : 'WARNING'),
            'WARNING' => $add('WAL_ARCHIVE_LAG', 'WAL_ARCHIVE_LAG', 'WARNING'),
            'UNKNOWN' => $add('WAL_STATUS_UNKNOWN', 'WAL_STATUS_UNKNOWN', 'WARNING'),
            default => null,
        };

        // Supplementary logical archive: surfaced, but never a CRITICAL substitute for PITR evidence.
        $logical = $operations->where('type', 'LOGICAL_BACKUP');
        $lastLogical = $logical->where('status', 'SUCCEEDED')->max('completed_at');
        if (($latestLogical = $logical->first()) !== null && $this->failedOrStuck($latestLogical, $now)) {
            $add('LOGICAL_BACKUP_FAILED_OR_STUCK', 'LOGICAL_BACKUP_FAILED', 'WARNING');
        }
        if (config('backup.logical_enabled') && ($lastLogical === null || $now - $lastLogical > config('backup.logical_stale_days') * 86400)) {
            $add('LOGICAL_BACKUP_MISSING_OR_STALE', 'LOGICAL_BACKUP_STALE', 'WARNING');
        }

        // Go-live evidence: attestations expire with the restore-test interval.
        $checkedAt = $evidence['controls_checked_at'] ?? null;
        $controlsFresh = $checkedAt !== null && $checkedAt <= $now + 60 && $now - $checkedAt <= config('backup.restore_test_days') * 86400;
        $controls = [];
        foreach (BackupStatusService::CONTROLS as $control) {
            $controls[$control] = $controlsFresh && ($evidence['controls'][$control] ?? false) === true;
            if (! $controls[$control]) {
                $add(strtoupper($control).'_UNVERIFIED', 'CONTROL_UNVERIFIED', 'READINESS');
            }
        }
        $rpo = config('backup.rpo') === 'NEEDS_DECISION' ? 'NEEDS_DECISION' : 'CONFIGURED';
        $rto = config('backup.rto') === 'NEEDS_DECISION' ? 'NEEDS_DECISION' : 'CONFIGURED';
        if ($rpo === 'NEEDS_DECISION' || $rto === 'NEEDS_DECISION') {
            $add('RECOVERY_OBJECTIVES_NEED_DECISION', 'OBJECTIVES_NEED_DECISION', 'READINESS');
        }
        if (config('backup.alert_user_ids') === []) {
            $add('ALERT_RECIPIENTS_NOT_CONFIGURED', 'ALERT_RECIPIENTS_NOT_CONFIGURED', 'READINESS');
        }

        $issues = array_values($issues);
        $critical = collect($issues)->firstWhere('severity', 'CRITICAL');
        $warning = collect($issues)->firstWhere('severity', 'WARNING');
        if (! $available) {
            // Missing infrastructure is never healthy; outside production it is an expected state, not an incident.
            $overall = $enforced ? 'CRITICAL' : match ($infra->status) {
                InfrastructureSnapshot::NOT_CONFIGURED => 'NOT_CONFIGURED',
                InfrastructureSnapshot::UNKNOWN => 'UNKNOWN',
                default => 'INFRASTRUCTURE_UNAVAILABLE',
            };
            $reason = $infra->reasonCode;
        } else {
            $overall = $critical ? 'CRITICAL' : ($warning ? 'WARNING' : 'HEALTHY');
            $reason = $critical['reason'] ?? $warning['reason'] ?? null;
        }
        $readiness = $overall === 'HEALTHY' && $issues === [] ? 'READY' : 'NOT_READY';

        $backups = collect($repositories)->flatMap(fn ($repository) => $repository['backups']);
        $latestBackup = $backups->max('completed_at');
        $timestamps = [
            'latest_full_backup_at' => $backups->where('type', 'full')->max('completed_at'),
            'latest_incremental_backup_at' => $backups->where('type', '!=', 'full')->max('completed_at'),
            'latest_backup_at' => $latestBackup,
            'backup_age_seconds' => $latestBackup === null ? null : max(0, $now - $latestBackup),
            'latest_wal_at' => $wal['last_archived_at'] ?? null,
            'latest_verified_at' => collect($lastPassed['VERIFY'])->filter()->max(),
            'latest_restore_test_at' => collect($lastPassed['RESTORE_TEST'])->filter()->max(),
            'latest_logical_backup_at' => $lastLogical,
        ];
        [$repositoryStatus, $repositoryReason] = $this->repositorySummary($available, $repositories);
        [$backupStatus, $backupReason] = $this->backupSummary($available, $repositories);
        $verification = $this->worst($evidenceStates['VERIFY']);
        $restoreTest = $this->worst($evidenceStates['RESTORE_TEST']);

        return new BackupHealthStatus(
            overallStatus: $overall,
            infrastructureStatus: $infra->status,
            repositoryStatus: $repositoryStatus,
            backupStatus: $backupStatus,
            walStatus: $wal['status'],
            verificationStatus: $verification,
            restoreTestStatus: $restoreTest,
            reasonCode: $reason,
            message: BackupHealthStatus::message($reason, $overall, $enforced),
            readiness: $readiness,
            productionBlocker: $enforced && $readiness !== 'READY',
            enforced: $enforced,
            environment: app()->environment(),
            checkedAt: $infra->checkedAt ?? $now,
            timestamps: $timestamps,
            infrastructure: ['driver' => $infra->driver, 'stanza' => $infra->stanza, 'binary' => $infra->binary, 'version' => $infra->version, 'diagnostics' => $infra->diagnostics],
            components: [
                'infrastructure' => ['status' => $infra->status, 'reason_code' => $infra->reasonCode],
                'repository' => ['status' => $repositoryStatus, 'reason_code' => $repositoryReason],
                'backup' => ['status' => $backupStatus, 'reason_code' => $backupReason],
                'wal' => $wal,
                'verification' => ['status' => $verification, 'reason_code' => $verification === 'PASSED' ? null : 'VERIFY_'.$verification],
                'restore_test' => ['status' => $restoreTest, 'reason_code' => $restoreTest === 'PASSED' ? null : 'RESTORE_TEST_'.$restoreTest],
            ],
            repositories: $repositories,
            issues: $issues,
            controls: $controls,
            operations: $evidence['operations'],
            rpo: $rpo,
            rto: $rto,
        );
    }

    private function repository(array $repo, array $capacity, int $now, Closure $add): array
    {
        $id = $repo['id'];
        $backups = collect($repo['backups'])->sortByDesc('completed_at')->values();
        $latest = $backups->first();
        $full = $backups->firstWhere('type', 'full');
        $age = $latest === null ? null : max(0, $now - $latest['completed_at']);
        $usage = $repo['usage_percent'] ?? $capacity[$id] ?? null;
        $state = 'HEALTHY';
        $reason = null;
        $escalate = function (string $to, string $why, string $severity, ?string $code = null) use (&$state, &$reason, $add, $id): void {
            $add($code ?? "REPOSITORY_{$id}_{$why}", $why, $severity);
            if (self::BACKUP_RANK[$to] > self::BACKUP_RANK[$state]) {
                [$state, $reason] = [$to, $why];
            }
        };
        if ($repo['status'] !== 'AVAILABLE') {
            $why = in_array($repo['status'], ['STANZA_NOT_FOUND', 'PERMISSION_DENIED', 'INVALID_CONFIGURATION', 'REPOSITORY_NOT_CONFIGURED'], true)
                ? $repo['status'] : 'REPOSITORY_UNAVAILABLE';
            $add($why === 'STANZA_NOT_FOUND' ? 'STANZA_NOT_FOUND' : "REPOSITORY_{$id}_".str_replace('REPOSITORY_', '', $why), $why, 'CRITICAL');
            [$state, $reason] = ['UNKNOWN', null];
        } else {
            if ($repo['errored_backups'] > 0) {
                $escalate('CRITICAL', 'BACKUP_ERRORS', 'CRITICAL');
            }
            if ($latest === null) {
                $escalate('BACKUP_NOT_FOUND', 'BACKUP_NOT_FOUND', 'CRITICAL');
            } elseif ($age > config('backup.critical_hours') * 3600) {
                $escalate('CRITICAL', 'BACKUP_STALE', 'CRITICAL');
            } elseif ($age > config('backup.stale_hours') * 3600) {
                $escalate('WARNING', 'BACKUP_STALE', 'WARNING');
            }
            if ($latest !== null && ($full === null || $now - $full['completed_at'] > config('backup.full_stale_days') * 86400)) {
                $escalate('WARNING', 'FULL_BACKUP_STALE', 'WARNING');
            }
            if ($repo['encrypted'] === false) {
                $add("REPOSITORY_{$id}_NOT_ENCRYPTED", 'NOT_ENCRYPTED', 'WARNING');
            }
            if ($usage === null) {
                $add("REPOSITORY_{$id}_CAPACITY_UNKNOWN", 'CAPACITY_UNKNOWN', 'READINESS');
            } elseif ($usage >= config('backup.storage_warning_percent')) {
                $add("REPOSITORY_{$id}_CAPACITY_WARNING", 'CAPACITY_WARNING', 'WARNING');
            }
        }

        return ['id' => $id, 'name' => config("backup.repository_names.{$id}", "Repository {$id}"), 'status' => $repo['status'],
            'backup_status' => $state, 'backup_reason' => $reason, 'encrypted' => $repo['encrypted'], 'latest' => $latest, 'full' => $full,
            'daily' => $backups->first(fn ($backup) => $backup['type'] !== 'full'), 'age_seconds' => $age, 'usage_percent' => $usage,
            'wal_max' => $repo['wal_max'] ?? null, 'errored_backups' => $repo['errored_backups'], 'backups' => $backups->all()];
    }

    /** @return array{0: string, 1: ?string} */
    private function repositorySummary(bool $available, array $repositories): array
    {
        if (! $available) {
            return ['NOT_CHECKED', null];
        }
        $statuses = array_column($repositories, 'status');
        foreach (['STANZA_NOT_FOUND', 'PERMISSION_DENIED', 'INVALID_CONFIGURATION', 'REPOSITORY_UNAVAILABLE', 'REPOSITORY_NOT_CONFIGURED'] as $status) {
            if (in_array($status, $statuses, true)) {
                return [$status === 'REPOSITORY_NOT_CONFIGURED' ? 'REPOSITORY_UNAVAILABLE' : $status, $status];
            }
        }

        return ['AVAILABLE', null];
    }

    /** @return array{0: string, 1: ?string} */
    private function backupSummary(bool $available, array $repositories): array
    {
        $usable = array_filter($repositories, fn ($repository) => $repository['status'] === 'AVAILABLE');
        if (! $available) {
            return ['NOT_CHECKED', null];
        }
        if ($usable === []) {
            return ['UNKNOWN', null];
        }
        usort($usable, fn ($a, $b) => self::BACKUP_RANK[$b['backup_status']] <=> self::BACKUP_RANK[$a['backup_status']]);

        return [$usable[0]['backup_status'], $usable[0]['backup_reason']];
    }

    private function worst(array $states): string
    {
        return collect($states)->sortByDesc(fn ($state) => self::EVIDENCE_RANK[$state])->first() ?? 'UNKNOWN';
    }

    private function failedOrStuck(array $operation, int $now): bool
    {
        return $operation['status'] === 'FAILED'
            || ($operation['status'] === 'RUNNING' && $now - $operation['started_at'] > config('backup.critical_hours') * 3600);
    }
}
