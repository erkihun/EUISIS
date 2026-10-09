<?php

declare(strict_types=1);

namespace App\Actions\IdCards;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CardStatus;
use App\Models\IdCard;
use App\Models\User;
use DomainException;

readonly class ReportLostOrDamagedCardAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(IdCard $card, string $status, ?User $actor = null, ?string $reason = null): IdCard
    {
        $allowedTransitions = [
            'lost' => [CardStatus::Active, CardStatus::Issued, CardStatus::Printed],
            'damaged' => [CardStatus::Active, CardStatus::Issued, CardStatus::Printed],
            'suspended' => [CardStatus::Active, CardStatus::Issued, CardStatus::Printed],
        ];

        if (! array_key_exists($status, $allowedTransitions)) {
            throw new DomainException('Unsupported card incident status: '.$status);
        }

        if (! in_array($card->status, $allowedTransitions[$status], true)) {
            throw new DomainException("Cannot mark card as {$status}. Current status: ".$card->status->value);
        }

        $cardStatus = CardStatus::from($status);
        $oldValues = $card->only(['status']);

        $card->update(['status' => $cardStatus]);
        $fresh = $card->fresh();

        $eventType = match ($cardStatus) {
            CardStatus::Lost => AuditEventType::CardLost,
            CardStatus::Damaged => AuditEventType::CardDamaged,
            CardStatus::Suspended => AuditEventType::CardSuspended,
            default => AuditEventType::CardRevoked,
        };

        $this->writeAuditLogAction->execute(
            $eventType,
            $actor,
            $fresh,
            $fresh?->employee?->currentAssignment?->organization_id,
            oldValues: $oldValues,
            newValues: ['status' => $cardStatus->value],
            reason: $reason ?? $status,
        );

        return $fresh;
    }
}
