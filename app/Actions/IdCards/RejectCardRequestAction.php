<?php

declare(strict_types=1);

namespace App\Actions\IdCards;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CardRequestStatus;
use App\Models\CardRequest;
use App\Models\User;
use DomainException;

readonly class RejectCardRequestAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(CardRequest $cardRequest, User $actor, string $reason): CardRequest
    {
        if (in_array($cardRequest->status, [CardRequestStatus::Approved, CardRequestStatus::Rejected, CardRequestStatus::Cancelled], true)) {
            throw new DomainException('Cannot reject a request that is already approved, rejected, or cancelled.');
        }

        $oldValues = $cardRequest->only(['status', 'rejected_by', 'rejected_at']);

        $cardRequest->update([
            'status' => CardRequestStatus::Rejected,
            'rejected_by' => $actor->getKey(),
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $fresh = $cardRequest->fresh();

        $this->writeAuditLogAction->execute(
            AuditEventType::CardRejected,
            $actor,
            $fresh,
            $fresh?->employee?->currentAssignment?->organization_id,
            oldValues: $oldValues,
            newValues: ['status' => CardRequestStatus::Rejected->value, 'rejection_reason' => $reason],
            reason: $reason,
        );

        return $fresh;
    }
}
