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
use Illuminate\Support\Facades\DB;

/**
 * Lifts a suspension: the card becomes active again under the same rules as a
 * first activation (active employee, no other active card).
 */
readonly class ReinstateCardAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(IdCard $card, User $actor, string $reason): IdCard
    {
        return DB::transaction(function () use ($card, $actor, $reason): IdCard {
            $card = IdCard::query()->whereKey($card->getKey())->lockForUpdate()->firstOrFail();

            if ($card->status !== CardStatus::Suspended) {
                throw new DomainException('Only a suspended card can be reinstated. Current status: '.$card->status->value);
            }

            if ($card->employee->status !== EmployeeStatus::Active) {
                throw new DomainException('Cannot reinstate a card for an inactive employee.');
            }

            $hasOtherActiveCard = IdCard::query()
                ->where('employee_id', $card->employee_id)
                ->where('id', '!=', $card->id)
                ->where('status', CardStatus::Active->value)
                ->exists();

            if ($hasOtherActiveCard) {
                throw new DomainException('Employee already has another active card. Revoke or replace it first.');
            }

            $oldValues = $card->only(['status']);
            $card->update(['status' => CardStatus::Active]);
            $fresh = $card->fresh();

            $this->writeAuditLogAction->execute(
                AuditEventType::CardReinstated,
                $actor,
                $fresh,
                $fresh->employee?->currentAssignment?->organization_id,
                oldValues: $oldValues,
                newValues: ['status' => CardStatus::Active->value],
                reason: $reason,
            );

            return $fresh;
        });
    }
}
