<?php

declare(strict_types=1);

namespace App\Actions\IdCards;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\IdCard;
use App\Models\User;
use DomainException;

readonly class ActivateCardAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(IdCard $card, User $actor, ?string $notes = null): IdCard
    {
        if ($card->status !== CardStatus::Issued) {
            throw new DomainException('Only issued cards can be activated. Current status: '.$card->status->value);
        }

        if ($card->employee->status !== EmployeeStatus::Active) {
            throw new DomainException('Cannot activate a card for an inactive employee.');
        }

        $hasOtherActiveCard = IdCard::query()
            ->where('employee_id', $card->employee_id)
            ->where('id', '!=', $card->id)
            ->where('status', CardStatus::Active->value)
            ->exists();

        if ($hasOtherActiveCard) {
            throw new DomainException('Employee already has another active card. Revoke or replace it first.');
        }

        $oldValues = $card->only(['status', 'activated_at']);

        $card->update([
            'status' => CardStatus::Active,
            'activated_at' => now(),
            'notes' => $notes,
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::CardActivated,
            $actor,
            $card->fresh(),
            $card->employee?->currentAssignment?->organization_id,
            oldValues: $oldValues,
            newValues: ['status' => CardStatus::Active->value, 'activated_at' => now()->toIso8601String()],
        );

        return $card->fresh();
    }
}
