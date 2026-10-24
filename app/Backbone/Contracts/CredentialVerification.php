<?php

declare(strict_types=1);

namespace App\Backbone\Contracts;

use App\Backbone\Data\CredentialFormat;
use App\Backbone\Data\CredentialVerificationData;

/**
 * One question for a point of service: is this scanned card a valid
 * credential, and whose is it. Built on CredentialLookup (which card) and
 * CardCredentialStatus (is it valid); it adds only the minimal holder
 * identity. An unknown value is a "not_found" answer, never an exception.
 */
interface CredentialVerification
{
    public function verify(string $scannedValue, CredentialFormat ...$formats): CredentialVerificationData;

    /** The same answer for a card known by its public card UUID. */
    public function verifyCardUuid(string $cardUuid): CredentialVerificationData;
}
