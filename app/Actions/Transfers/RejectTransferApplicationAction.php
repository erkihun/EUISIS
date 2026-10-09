<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\TransferApplicationStatus;
use App\Models\TransferApplication;
use App\Models\TransferScreeningReview;
use App\Models\User;
use DomainException;

readonly class RejectTransferApplicationAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(TransferApplication $application, User $actor, string $reason): TransferApplication
    {
        if ($application->status->isFinal()) {
            throw new DomainException('This application is already in a final state and cannot be rejected.');
        }

        $application->update([
            'status' => TransferApplicationStatus::Rejected->value,
            'rejected_reason' => $reason,
        ]);

        TransferScreeningReview::query()->create([
            'transfer_application_id' => $application->id,
            'reviewer_id' => $actor->id,
            'action' => 'rejected',
            'notes' => $reason,
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::TransferApplicationRejected,
            $actor,
            $application->fresh(),
            $application->releasing_organization_id,
            newValues: $application->fresh()->toArray(),
            reason: $reason,
        );

        return $application->fresh();
    }
}
