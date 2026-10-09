<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\TransferOverrideStatus;
use App\Models\TransferRuleOverride;
use App\Models\User;
use DomainException;

readonly class DecideTransferOverrideAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function approve(TransferRuleOverride $override, User $actor): TransferRuleOverride
    {
        return $this->decide($override, $actor, TransferOverrideStatus::Approved);
    }

    public function reject(TransferRuleOverride $override, User $actor, string $reason = ''): TransferRuleOverride
    {
        return $this->decide($override, $actor, TransferOverrideStatus::Rejected, $reason);
    }

    private function decide(
        TransferRuleOverride $override,
        User $actor,
        TransferOverrideStatus $decision,
        string $reason = '',
    ): TransferRuleOverride {
        if ($override->status !== TransferOverrideStatus::Pending) {
            throw new DomainException('This override has already been decided.');
        }

        $override->update([
            'status' => $decision->value,
            'approved_by' => $actor->id,
            'decided_at' => now(),
            'reason' => $reason ?: $override->reason,
        ]);

        $auditEvent = $decision === TransferOverrideStatus::Approved
            ? AuditEventType::TransferOverrideApproved
            : AuditEventType::TransferOverrideRejected;

        $this->writeAuditLogAction->execute(
            $auditEvent,
            $actor,
            $override->transferApplication,
            null,
            newValues: $override->toArray(),
        );

        return $override->fresh();
    }
}
