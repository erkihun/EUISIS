<?php

namespace App\Services\Backup;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\BackupRestoreRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RestoreWorkflow
{
    public const EVIDENCE = '/\A[A-Za-z0-9][A-Za-z0-9._-]{2,79}\z/';

    // Browser actions only move paperwork. Operator actions are recorded from the CLI by the person
    // executing the runbook; no action here runs a restore, touches a repository or reads a secret.
    public const BROWSER_ACTIONS = ['review', 'approve', 'authorize-production', 'reject', 'cancel'];

    public const OPERATOR_ACTIONS = ['test-start', 'test-pass', 'test-fail', 'start', 'complete', 'fail'];

    private const OPEN = ['REQUESTED', 'UNDER_REVIEW', 'APPROVED', 'TEST_RESTORE_VERIFIED', 'PRODUCTION_RESTORE_AUTHORIZED'];

    public function transition(BackupRestoreRequest $record, string $action, User $actor, ?string $evidence = null): void
    {
        // [permission, allowed from, to, audit event, evidence required]
        [$permission, $from, $to, $event, $needsEvidence] = match ($action) {
            'review' => ['backups.restore_review', ['REQUESTED'], 'UNDER_REVIEW', AuditEventType::RestoreReviewed, false],
            'approve' => ['backups.restore_approve', ['UNDER_REVIEW'], 'APPROVED', AuditEventType::RestoreApproved, false],
            'reject' => ['backups.restore_approve', ['REQUESTED', 'UNDER_REVIEW', 'APPROVED', 'TEST_RESTORE_VERIFIED'], 'REJECTED', AuditEventType::RestoreRejected, false],
            'cancel' => [null, self::OPEN, 'CANCELLED', AuditEventType::RestoreCancelled, false],
            'authorize-production' => ['backups.restore_approve', ['TEST_RESTORE_VERIFIED'], 'PRODUCTION_RESTORE_AUTHORIZED', AuditEventType::ProductionRestoreAuthorized, false],
            'test-start' => ['backups.run', ['APPROVED'], 'TEST_RESTORE_RUNNING', AuditEventType::RestoreTestStarted, true],
            'test-pass' => ['backups.run', ['TEST_RESTORE_RUNNING'], 'TEST_RESTORE_VERIFIED', AuditEventType::RestoreTestPassed, true],
            'test-fail' => ['backups.run', ['TEST_RESTORE_RUNNING'], 'FAILED', AuditEventType::RestoreTestFailed, false],
            'start' => ['backups.run', ['PRODUCTION_RESTORE_AUTHORIZED'], 'RESTORING', AuditEventType::ProductionRestoreStarted, true],
            'complete' => ['backups.run', ['RESTORING'], 'COMPLETED', AuditEventType::ProductionRestoreCompleted, true],
            'fail' => ['backups.run', ['RESTORING'], 'FAILED', AuditEventType::ProductionRestoreFailed, false],
            default => abort(422),
        };
        abort_unless($actor->isActive(), 403);
        abort_if($permission !== null && ! $actor->can($permission), 403);
        abort_if(in_array($action, self::OPERATOR_ACTIONS, true) && ! app()->runningInConsole(), 403);
        DB::transaction(function () use ($record, $action, $actor, $evidence, $from, $to, $event, $needsEvidence) {
            $record = BackupRestoreRequest::query()->lockForUpdate()->findOrFail($record->id);
            // Requesters may withdraw their own request; approvers may cancel any open request.
            abort_if($action === 'cancel' && ! $actor->can('backups.restore_approve')
                && ! ($actor->can('backups.restore_request') && $record->requested_by === $actor->id), 403);
            abort_if(in_array($action, ['approve', 'authorize-production'], true) && $record->requested_by === $actor->id, 403, 'A different person must approve recovery.');
            if (! in_array($record->status, $from, true)) {
                throw ValidationException::withMessages(['action' => 'Invalid recovery workflow transition.']);
            }
            if ($needsEvidence && (! $evidence || ! preg_match(self::EVIDENCE, $evidence))) {
                throw ValidationException::withMessages(['evidence_reference' => 'Provide the restricted evidence ticket identifier.']);
            }
            $record->status = $to;
            match ($action) {
                'review' => $record->reviewed_by = $actor->id,
                'approve' => $record->approved_by = $actor->id,
                'authorize-production' => $record->production_authorized_by = $actor->id,
                'test-start' => $record->started_at = now(),
                default => null,
            };
            if (in_array($to, ['COMPLETED', 'FAILED'], true)) {
                $record->completed_at = now();
            }
            // Fixed codes only: raw tool output and incident detail stay in the restricted record.
            if ($action === 'test-fail') {
                $record->failure_summary = 'TEST_RESTORE_FAILED: consult restricted incident record.';
            } elseif ($action === 'fail') {
                $record->failure_summary = 'PRODUCTION_RESTORE_FAILED: consult restricted incident record.';
            }
            if ($evidence && preg_match(self::EVIDENCE, $evidence)) {
                $record->evidence_reference = $evidence;
            }
            $record->save();
            app(WriteAuditLogAction::class)->execute($event, $actor, $record, newValues: ['status' => $to, 'evidence_reference' => $record->evidence_reference]);
        });
    }
}
