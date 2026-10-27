<?php

declare(strict_types=1);

namespace App\Services\Nfc;

/**
 * NFC credentials stay in EUISIS (docs/nfc-id-card-architecture.md there). The
 * cafeteria platform's attended scanner takes QR codes; a tap is denied with
 * its own reason so the operator falls back to the card's QR code.
 */
final class NfcCredentialService
{
    /** @return array{card: null, reason: string} */
    public function resolveForAttendedScan(string $credential): array
    {
        return ['card' => null, 'reason' => 'nfc_not_available'];
    }
}
