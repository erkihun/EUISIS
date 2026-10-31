<?php

declare(strict_types=1);

namespace App\Actions\IdCards;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CardStatus;
use App\Models\IdCard;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

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

        // The card row lock orders a revocation strictly against a service
        // transaction in flight (RecordServiceTransactionAction locks the same
        // row and re-reads it), and two concurrent revokes cannot both pass.
        // The audit row is written in the same transaction as the change and its event.
        return DB::transaction(function () use ($card, $actor, $revokableStatuses, $reason): IdCard {
            $card = IdCard::query()->whereKey($card->getKey())->lockForUpdate()->firstOrFail();

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

            $fresh = $card->fresh();

            $this->writeAuditLogAction->execute(
                AuditEventType::CardRevoked,
                $actor,
                $fresh,
                $fresh->employee?->currentAssignment?->organization_id,
                oldValues: $oldValues,
                newValues: ['status' => CardStatus::Revoked->value, 'revoke_reason' => $reason],
                reason: $reason,
            );

            return $fresh;
        });
    }
}
