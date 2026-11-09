<?php

declare(strict_types=1);

namespace App\Actions\IdCards;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CardRequestStatus;
use App\Models\CardRequest;
use App\Models\User;
use DomainException;

readonly class CancelCardRequestAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(CardRequest $cardRequest, User $actor, ?string $reason = null): CardRequest
    {
        if (in_array($cardRequest->status, [CardRequestStatus::Approved, CardRequestStatus::Rejected, CardRequestStatus::Cancelled], true)) {
            throw new DomainException('Cannot cancel a request that is already approved, rejected, or cancelled.');
        }

        $oldValues = $cardRequest->only(['status', 'cancelled_by', 'cancelled_at']);

        $cardRequest->update([
            'status' => CardRequestStatus::Cancelled,
            'cancelled_by' => $actor->getKey(),
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        $fresh = $cardRequest->fresh();

        $this->writeAuditLogAction->execute(
            AuditEventType::CardCancelled,
            $actor,
            $fresh,
            $fresh?->employee?->currentAssignment?->organization_id,
            oldValues: $oldValues,
            newValues: ['status' => CardRequestStatus::Cancelled->value, 'cancellation_reason' => $reason],
            reason: $reason,
        );

        return $fresh;
    }
}
