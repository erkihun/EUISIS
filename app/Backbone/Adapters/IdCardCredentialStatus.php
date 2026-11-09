<?php

declare(strict_types=1);

namespace App\Backbone\Adapters;

use App\Backbone\Contracts\CardCredentialStatus;
use App\Backbone\Data\CardCredential;
use App\Enums\CardStatus;
use App\Models\IdCard;
use Illuminate\Support\Str;

final class IdCardCredentialStatus implements CardCredentialStatus
{
    public function forCardUuid(string $cardUuid): ?CardCredential
    {
        if (! Str::isUuid($cardUuid)) {
            return null;
        }

        $card = IdCard::query()->where('public_card_uuid', $cardUuid)->first();

        return $card === null ? null : $this->evaluate($card);
    }

    public function evaluate(IdCard $card): CardCredential
    {
        $isExpired = $card->expires_at !== null && $card->expires_at->isPast();
        $qrRevoked = $card->qr_status !== null && $card->qr_status !== 'active';

        $valid = $card->status === CardStatus::Active
            && $card->revoked_at === null
            && ! $isExpired
            && ! $qrRevoked
            && (bool) $card->is_current;

        $status = match (true) {
            $card->status !== CardStatus::Active => $card->status->value,
            $card->revoked_at !== null || $qrRevoked => CardStatus::Revoked->value,
            $isExpired => CardStatus::Expired->value,
            ! $card->is_current => CardStatus::Replaced->value,
            default => CardStatus::Active->value,
        };

        return new CardCredential(
            cardRecordUuid: (string) $card->getKey(),
            cardUuid: $card->public_card_uuid,
            cardNumber: (string) $card->card_number,
            employeeUuid: (string) $card->employee_id,
            previousCardRecordUuid: $card->previous_card_id === null ? null : (string) $card->previous_card_id,
            valid: $valid,
            status: $status,
            isCurrent: (bool) $card->is_current,
            expiresAt: $card->expires_at?->toDateString(),
        );
    }
}
