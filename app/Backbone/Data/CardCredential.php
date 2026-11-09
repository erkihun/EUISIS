<?php

declare(strict_types=1);

namespace App\Backbone\Data;

/**
 * The state of one issued ID card credential.
 *
 * `cardUuid` is the public verification reference printed in the QR code
 * (`id_cards.public_card_uuid`); `cardNumber` is the printed business number.
 * A replacement card is a new credential with its own card UUID and number;
 * `previousCardRecordUuid` links it to the card it replaced. The employee UUID
 * never changes with the card.
 */
final readonly class CardCredential
{
    public function __construct(
        public string $cardRecordUuid,
        public ?string $cardUuid,
        public string $cardNumber,
        public string $employeeUuid,
        public ?string $previousCardRecordUuid,
        public bool $valid,
        public string $status,
        public bool $isCurrent,
        public ?string $expiresAt,
    ) {}
}
