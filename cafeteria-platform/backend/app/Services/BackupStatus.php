<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * What the Core knows about backups (ADR 0011) — never assumed. Backups run
 * outside the application (the database and object-storage tooling of the
 * host); that tooling writes a small JSON status file the Core only reads,
 * at CORE_BACKUP_STATUS_FILE:
 *
 *   {"last_success_at": "...", "type": "pg_dump full", "last_failure_at": null,
 *    "last_failure_reason": null, "encrypted": true, "retention_days": 30,
 *    "restore_tested_at": "...", "restore_test_result": "passed"}
 *
 * No file, or one that cannot be read, is "unknown — not integrated", never
 * healthy. A success older than CORE_BACKUP_MAX_AGE_HOURS is a warning, twice
 * that critical; a failure after the last success is critical.
 */
final class BackupStatus
{
    /** @return array{status: string, details: array<string, mixed>} */
    public function check(): array
    {
        $file = (string) config('core.backup_status_file');
        if ($file === '' || ! is_file($file) || ! is_readable($file)) {
            return ['status' => 'unknown', 'details' => ['integrated' => false, 'note' => 'Backup status is not integrated: no status file is configured or readable.']];
        }
        try {
            $data = json_decode((string) file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
            $success = isset($data['last_success_at']) ? CarbonImmutable::parse((string) $data['last_success_at']) : null;
            $failure = isset($data['last_failure_at']) ? CarbonImmutable::parse((string) $data['last_failure_at']) : null;
            $restore = isset($data['restore_tested_at']) ? CarbonImmutable::parse((string) $data['restore_tested_at']) : null;
        } catch (Throwable) {
            return ['status' => 'unknown', 'details' => ['integrated' => true, 'note' => 'The backup status file could not be read.']];
        }
        $maxAge = max(1, (int) config('core.backup_max_age_hours'));
        $ageHours = $success === null ? null : (int) $success->diffInHours(now(), true);
        $status = match (true) {
            $success === null => 'critical',
            $failure !== null && $failure->greaterThan($success) => 'critical',
            $ageHours > 2 * $maxAge => 'critical',
            $ageHours > $maxAge => 'warning',
            $restore === null || ($data['restore_test_result'] ?? null) !== 'passed' => 'warning',
            default => 'healthy',
        };

        return ['status' => $status, 'details' => [
            'integrated' => true,
            'last_success_at' => $success?->toIso8601String(),
            'age_hours' => $ageHours,
            'type' => isset($data['type']) ? mb_substr((string) $data['type'], 0, 80) : null,
            'last_failure_at' => $failure?->toIso8601String(),
            'last_failure_reason' => isset($data['last_failure_reason']) ? mb_substr((string) $data['last_failure_reason'], 0, 200) : null,
            'encrypted' => isset($data['encrypted']) ? (bool) $data['encrypted'] : null,
            'retention_days' => isset($data['retention_days']) ? (int) $data['retention_days'] : null,
            'restore_tested_at' => $restore?->toIso8601String(),
            'restore_test_result' => isset($data['restore_test_result']) ? (string) $data['restore_test_result'] : null,
        ]];
    }
}
