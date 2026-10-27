<?php

declare(strict_types=1);

namespace App\Backbone\Adapters;

use App\Backbone\Contracts\CardCredentialStatus;
use App\Backbone\Data\CardCredential;
use App\Models\IdCard;
use Illuminate\Support\Str;

/**
 * The single credential rule over the mirrored ID cards (the platform's
 * IdCard keeps EUISIS's string statuses): a card is valid when active,
 * current, not revoked, not expired and its QR reference is active.
 */
final class CafeteriaCardCredentialStatus implements CardCredentialStatus
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
        $status = (string) $card->status;

        $valid = $status === IdCard::ACTIVE
            && $card->revoked_at === null
            && ! $isExpired
            && ! $qrRevoked
            && (bool) $card->is_current;

        $reported = match (true) {
            $status !== IdCard::ACTIVE => $status,
            $card->revoked_at !== null || $qrRevoked => IdCard::REVOKED,
            $isExpired => IdCard::EXPIRED,
            ! $card->is_current => IdCard::REPLACED,
            default => IdCard::ACTIVE,
        };

        return new CardCredential(
            cardRecordUuid: (string) $card->getKey(),
            cardUuid: $card->public_card_uuid,
            cardNumber: (string) $card->card_number,
            employeeUuid: (string) $card->employee_id,
            previousCardRecordUuid: $card->previous_card_id === null ? null : (string) $card->previous_card_id,
            valid: $valid,
            status: $reported,
            isCurrent: (bool) $card->is_current,
            expiresAt: $card->expires_at?->toDateString(),
        );
    }
}
