<?php

declare(strict_types=1);

namespace App\Backbone\Contracts;

use App\Backbone\Data\CardCredential;
use App\Models\IdCard;

/**
 * The single rule for whether an issued card is a valid credential.
 *
 * Valid means all of: status active, not revoked, not expired, QR still
 * active and the employee's current card (master backbone audit F5).
 */
interface CardCredentialStatus
{
    public function forCardUuid(string $cardUuid): ?CardCredential;

    public function evaluate(IdCard $card): CardCredential;
}
