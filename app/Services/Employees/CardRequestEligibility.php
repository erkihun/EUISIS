<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\CardRequestStatus;
use App\Enums\CardRequestType;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\IdCard;
use Illuminate\Support\Collection;

class CardRequestEligibility
{
    /** @param Collection<int, IdCard> $cards */
    public function allows(Employee $employee, CardRequestType $type, Collection $cards, bool $pending): bool
    {
        if ($pending || $employee->status !== EmployeeStatus::Active || $employee->current_assignment_id === null) {
            return false;
        }

        if ($type === CardRequestType::New) {
            return $cards->isEmpty();
        }

        $card = $this->previousCard($cards);
        if ($card === null || $cards->contains(fn (IdCard $other) => $other->id !== $card->id
            && in_array($other->status, [CardStatus::Active, CardStatus::Issued, CardStatus::Printed, CardStatus::PendingPrint], true))) {
            return false;
        }

        return match ($type) {
            CardRequestType::Renewal => $card->status === CardStatus::Expired,
            CardRequestType::Lost => $card->status === CardStatus::Lost,
            CardRequestType::Damaged => $card->status === CardStatus::Damaged,
            CardRequestType::Replacement => in_array($card->status, [CardStatus::Lost, CardStatus::Damaged, CardStatus::Revoked, CardStatus::Expired, CardStatus::Suspended], true),
            CardRequestType::Correction => $card->reprint_required && in_array($card->status, [CardStatus::Active, CardStatus::Issued, CardStatus::Printed, CardStatus::Suspended, CardStatus::Revoked], true),
            default => false,
        };
    }

    /** @param Collection<int, IdCard> $cards */
    public function previousCard(Collection $cards): ?IdCard
    {
        return $cards->sortByDesc('created_at')->first();
    }

    public function pendingStatuses(): array
    {
        return [CardRequestStatus::Draft->value, CardRequestStatus::Submitted->value, CardRequestStatus::Verified->value];
    }
}
