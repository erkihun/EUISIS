<?php

declare(strict_types=1);

namespace App\Actions\IdCards;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CardStatus;
use App\Models\IdCard;
use App\Models\User;
use DomainException;

readonly class RevokeCardAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(IdCard $card, User $actor, string $reason): IdCard
    {
        $revokableStatuses = [
            CardStatus::Active,
            CardStatus::Issued,
            CardStatus::Printed,
            CardStatus::PendingPrint,
        ];

        if (! in_array($card->status, $revokableStatuses, true)) {
            throw new DomainException('Cannot revoke a card with status: '.$card->status->value);
        }

        $oldValues = $card->only(['status', 'revoked_at', 'revoke_reason']);

        $card->update([
            'status' => CardStatus::Revoked,
            'revoked_at' => now(),
            'revoke_reason' => $reason,
            'is_current' => false,
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::CardRevoked,
            $actor,
            $card->fresh(),
            $card->employee?->currentAssignment?->organization_id,
            oldValues: $oldValues,
            newValues: ['status' => CardStatus::Revoked->value, 'revoke_reason' => $reason],
            reason: $reason,
        );

        return $card->fresh();
    }
}
