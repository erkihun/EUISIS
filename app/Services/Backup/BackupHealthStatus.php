<?php

namespace App\Services\Backup;

/**
 * Stable, sanitized backup health result. It contains status codes, timestamps, allowlisted backup
 * metadata and process diagnostics only: never secrets, raw tool output or environment values.
 */
final class BackupHealthStatus
{
    public const MESSAGES = [
        'NOT_CONFIGURED' => 'Production backup infrastructure is not configured in this development environment.',
        'INFRASTRUCTURE_MISSING' => 'Production backup infrastructure is unavailable.',
        'COMMAND_NOT_FOUND' => 'pgBackRest is not installed or is not available at the configured path.',
        'CONFIG_NOT_FOUND' => 'The configured pgBackRest configuration file was not found.',
        'INVALID_CONFIGURATION' => 'The backup integration configuration is invalid.',
        'PERMISSION_DENIED' => 'The service account is not permitted to run pgBackRest or to read its configuration or repository.',
        'COMMAND_TIMEOUT' => 'The pgBackRest status command timed out.',
        'COMMAND_FAILED' => 'The pgBackRest status command failed.',
        'DATABASE_UNAVAILABLE' => 'pgBackRest could not connect to PostgreSQL.',
        'INVALID_COMMAND_OUTPUT' => 'pgBackRest returned output that could not be validated.',
        'REPORT_NOT_CONFIGURED' => 'No backup status report path is configured.',
        'REPORT_NOT_FOUND' => 'The published backup status report was not found.',
        'REPORT_STALE' => 'The published backup status report is stale.',
        'INVALID_REPORT' => 'The published backup status report is not valid.',
        'STANZA_NOT_FOUND' => 'The configured pgBackRest stanza does not exist in the repository.',
        'REPOSITORY_UNAVAILABLE' => 'A backup repository is unavailable.',
        'REPOSITORY_NOT_CONFIGURED' => 'An expected backup repository is not configured in pgBackRest.',
        'BACKUP_NOT_FOUND' => 'Backup infrastructure is configured, but no valid backup was found.',
        'BACKUP_ERRORS' => 'A backup recorded errors and is not a usable recovery point.',
        'BACKUP_STALE' => 'The latest backup is older than the policy threshold.',
        'FULL_BACKUP_STALE' => 'The latest full backup is older than the policy threshold.',
        'NOT_ENCRYPTED' => 'A backup repository is not encrypted.',
        'CAPACITY_WARNING' => 'Backup repository storage is above the warning threshold.',
        'CAPACITY_UNKNOWN' => 'Backup repository capacity is not reported.',
        'WAL_ARCHIVE_UNHEALTHY' => 'WAL archiving is not healthy; point-in-time recovery is degraded.',
        'WAL_ARCHIVE_LAG' => 'No WAL segment has been archived recently.',
        'WAL_STATUS_UNKNOWN' => 'WAL archive status could not be established.',
        'VERIFY_FAILED' => 'The latest backup verification failed.',
        'VERIFY_OVERDUE' => 'Backup verification is overdue.',
        'VERIFY_NOT_RECORDED' => 'No successful backup verification has been recorded.',
        'VERIFY_UNKNOWN' => 'Backup verification history is unavailable.',
        'RESTORE_TEST_FAILED' => 'The latest isolated restore test failed.',
        'RESTORE_TEST_OVERDUE' => 'An isolated restore test is overdue.',
        'RESTORE_TEST_NOT_RECORDED' => 'No successful isolated restore test has been recorded.',
        'RESTORE_TEST_UNKNOWN' => 'Restore test history is unavailable.',
        'OPERATION_FAILED' => 'The latest scheduled backup operation failed.',
        'LOGICAL_BACKUP_FAILED' => 'The latest supplementary logical backup failed.',
        'LOGICAL_BACKUP_STALE' => 'The supplementary logical backup is missing or stale.',
        'HISTORY_UNAVAILABLE' => 'Backup operation history is unavailable; apply the backup migration.',
        'CONTROL_UNVERIFIED' => 'A recovery control lacks current evidence.',
        'OBJECTIVES_NEED_DECISION' => 'RPO and RTO have not been approved.',
        'ALERT_RECIPIENTS_NOT_CONFIGURED' => 'No backup alert recipients are configured.',
    ];

    public function __construct(
        public readonly string $overallStatus,
        public readonly string $infrastructureStatus,
        public readonly string $repositoryStatus,
        public readonly string $backupStatus,
        public readonly string $walStatus,
        public readonly string $verificationStatus,
        public readonly string $restoreTestStatus,
        public readonly ?string $reasonCode,
        public readonly string $message,
        public readonly string $readiness,
        public readonly bool $productionBlocker,
        public readonly bool $enforced,
        public readonly string $environment,
        public readonly int $checkedAt,
        /** @var array<string, ?int> latest_* timestamps and backup_age_seconds */
        public readonly array $timestamps,
        /** @var array{driver: string, stanza: ?string, binary: ?string, version: ?string, diagnostics: array} */
        public readonly array $infrastructure,
        /** @var array<string, array{status: string, reason_code: ?string}> */
        public readonly array $components,
        public readonly array $repositories,
        /** @var list<array{code: string, reason: string, severity: string}> */
        public readonly array $issues,
        public readonly array $controls,
        public readonly array $operations,
        public readonly string $rpo,
        public readonly string $rto,
    ) {}

    public static function message(?string $reason, string $overall, bool $enforced): string
    {
        if ($overall === 'HEALTHY') {
            return 'Backups, repositories and WAL archiving are healthy.';
        }
        if ($reason === 'NOT_CONFIGURED') {
            return self::MESSAGES[$enforced ? 'INFRASTRUCTURE_MISSING' : 'NOT_CONFIGURED'];
        }

        return self::MESSAGES[$reason] ?? 'Backup status could not be established.';
    }

    public function toArray(): array
    {
        return [
            'overall_status' => $this->overallStatus,
            'infrastructure_status' => $this->infrastructureStatus,
            'repository_status' => $this->repositoryStatus,
            'backup_status' => $this->backupStatus,
            'wal_status' => $this->walStatus,
            'verification_status' => $this->verificationStatus,
            'restore_test_status' => $this->restoreTestStatus,
            'reason_code' => $this->reasonCode,
            'message' => $this->message,
            'readiness' => $this->readiness,
            'production_blocker' => $this->productionBlocker,
            'enforced' => $this->enforced,
            'environment' => $this->environment,
            'checked_at' => $this->checkedAt,
            ...$this->timestamps,
            'driver' => $this->infrastructure['driver'],
            'stanza' => $this->infrastructure['stanza'],
            'binary' => $this->infrastructure['binary'],
            'version' => $this->infrastructure['version'],
            'components' => $this->components,
            'repositories' => $this->repositories,
            'issues' => $this->issues,
            'controls' => $this->controls,
            'rpo' => $this->rpo,
            'rto' => $this->rto,
            'operations' => $this->operations,
            'diagnostics' => $this->infrastructure['diagnostics'],
        ];
    }
}
