<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\TransferApplicationStatus;
use App\Enums\TransferApprovalStatus;
use App\Enums\TransferApprovalType;
use App\Models\TransferApproval;
use App\Models\User;
use DomainException;

readonly class RejectTransferApprovalAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(TransferApproval $approval, User $actor, string $reason): TransferApproval
    {
        if (! $approval->isPending()) {
            throw new DomainException('This approval has already been decided.');
        }

        $approval->update([
            'status' => TransferApprovalStatus::Rejected->value,
            'approver_id' => $actor->id,
            'rejection_reason' => $reason,
            'decided_at' => now(),
        ]);

        $application = $approval->transferApplication;
        $application->update([
            'status' => TransferApplicationStatus::Rejected->value,
            'rejected_reason' => $reason,
        ]);

        $auditEvent = match ($approval->approval_type) {
            TransferApprovalType::Release => AuditEventType::TransferReleaseRejected,
            TransferApprovalType::Receiving => AuditEventType::TransferReceivingRejected,
            TransferApprovalType::Final => AuditEventType::TransferFinalRejected,
        };

        $this->writeAuditLogAction->execute(
            $auditEvent,
            $actor,
            $application->fresh(),
            $actor->organizationScopes()->first()?->organization_id,
            newValues: $approval->toArray(),
            reason: $reason,
        );

        return $approval->fresh();
    }
}
