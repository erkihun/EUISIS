<?php

namespace App\Services\Backup;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Events\BackupHealthAlert;
use App\Models\BackupOperation;
use App\Models\User;
use App\Notifications\BackupHealthNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BackupMonitor
{
    public function sync(array $status): void
    {
        foreach ($status['operations'] as $operation) {
            DB::transaction(function () use ($operation) {
                $record = BackupOperation::query()->lockForUpdate()->find($operation['id']);
                if ($record && ($record->status === $operation['status'] || $record->status !== 'RUNNING')) {
                    return;
                }
                if ($record && ($record->type !== $operation['type'] || $record->repository !== $operation['repository'])) {
                    return;
                }
                $attributes = [...$operation, 'started_at' => CarbonImmutable::createFromTimestampUTC($operation['started_at']),
                    'completed_at' => isset($operation['completed_at']) ? CarbonImmutable::createFromTimestampUTC($operation['completed_at']) : null,
                    'recovery_target' => isset($operation['recovery_target']) ? CarbonImmutable::createFromTimestampUTC($operation['recovery_target']) : null];
                if (! $record) {
                    $record = BackupOperation::create($attributes);
                    $this->audit($record, 'RUNNING');
                } else {
                    $record->update($attributes);
                }
                if ($record->status !== 'RUNNING') {
                    $this->audit($record, $record->status);
                }
            });
        }
        // An intentionally unconfigured development environment is not an incident.
        if ($status['overall_status'] === 'HEALTHY' || ($status['overall_status'] === 'NOT_CONFIGURED' && ! $status['enforced'])) {
            Cache::forget('backup.alert.last');

            return;
        }
        $issues = array_column($status['issues'], 'code');
        $fingerprint = hash('sha256', json_encode([$status['overall_status'], $issues]));
        if (Cache::get('backup.alert.last') === $fingerprint) {
            return;
        }
        event(new BackupHealthAlert($status['overall_status'], $issues));
        foreach (User::query()->whereIn('id', config('backup.alert_user_ids'))->get() as $user) {
            if ($user->isActive() && $user->can('backups.view_logs') && $user->can('backups.view_status')) {
                $user->notify(new BackupHealthNotification($status['overall_status'], $issues));
            }
        }
        Cache::put('backup.alert.last', $fingerprint, now()->addMinutes(config('backup.alert_repeat_minutes')));
    }

    private function audit(BackupOperation $record, string $status): void
    {
        $event = match ($record->type) {
            'RESTORE_TEST' => match ($status) {
                'RUNNING' => AuditEventType::RestoreTestStarted,
                'SUCCEEDED' => AuditEventType::RestoreTestPassed,
                default => AuditEventType::RestoreTestFailed,
            },
            'VERIFY' => match ($status) {
                'RUNNING' => AuditEventType::BackupVerificationStarted,
                'SUCCEEDED' => AuditEventType::BackupVerified,
                default => AuditEventType::BackupVerificationFailed,
            },
            'RETENTION_CLEANUP' => AuditEventType::RetentionCleanup,
            default => match ($status) {
                'RUNNING' => AuditEventType::BackupStarted,
                'SUCCEEDED' => AuditEventType::BackupCompleted,
                default => AuditEventType::BackupFailed,
            },
        };
        app(WriteAuditLogAction::class)->execute($event, null, $record, newValues: ['status' => $status, 'type' => $record->type, 'repository' => $record->repository]);
    }
}
