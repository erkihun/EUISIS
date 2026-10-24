<?php

declare(strict_types=1);

namespace App\Backbone\Contracts;

use App\Backbone\Data\CardCredential;
use App\Backbone\Data\CredentialFormat;

/**
 * Turns a scanned value into the ID card credential it names. The one place
 * that parses card QR values; each service says which formats it accepts.
 * A found card is returned whatever its state: deciding whether it may be
 * used is CardCredentialStatus / ServiceEligibility, and a refusal of a
 * revoked card is evidence too.
 */
interface CredentialLookup
{
    public function resolveScan(string $value, CredentialFormat ...$formats): ?CardCredential;
}
