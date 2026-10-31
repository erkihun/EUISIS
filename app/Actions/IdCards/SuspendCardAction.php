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

/**
 * Suspends an active or issued card: a reversible block (ReinstateCardAction undoes
 * it), unlike revocation. Every service gate already refuses a suspended card.
 */
readonly class SuspendCardAction
{
    public const SUSPENDABLE = [CardStatus::Active, CardStatus::Issued];

    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(IdCard $card, User $actor, string $reason): IdCard
    {
        // The card row lock orders a suspension against a service transaction in
        // flight (RecordServiceTransactionAction locks the same row), as revocation does.
        return DB::transaction(function () use ($card, $actor, $reason): IdCard {
            $card = IdCard::query()->whereKey($card->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($card->status, self::SUSPENDABLE, true)) {
                throw new DomainException('Cannot suspend a card with status: '.$card->status->value);
            }

            $oldValues = $card->only(['status']);
            $card->update(['status' => CardStatus::Suspended]);
            $fresh = $card->fresh();

            $this->writeAuditLogAction->execute(
                AuditEventType::CardSuspended,
                $actor,
                $fresh,
                $fresh->employee?->currentAssignment?->organization_id,
                oldValues: $oldValues,
                newValues: ['status' => CardStatus::Suspended->value],
                reason: $reason,
            );

            return $fresh;
        });
    }
}
