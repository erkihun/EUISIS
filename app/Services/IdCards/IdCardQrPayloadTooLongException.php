<?php

declare(strict_types=1);

namespace App\Services\IdCards;

use RuntimeException;

/**
 * The card's verification URL does not fit any allowed QR symbol.
 *
 * The symbol grows to fit a longer payload, up to the configured maximum
 * version. Beyond that the code is reported rather than printed: a denser
 * symbol would eventually stop scanning reliably from a card-sized print, and
 * error correction is never lowered to buy space.
 */
final class IdCardQrPayloadTooLongException extends RuntimeException
{
    /** Machine-readable marker for API and log consumers. */
    public const CODE = 'QR_PAYLOAD_TOO_LONG';

    public static function forPayload(
        string $payload,
        int $requiredBits,
        int $capacityBits,
        int $maximumVersion,
        string $eccName,
    ): self {
        return new self(sprintf(
            '%s: the payload is %d characters (%d bits) against a %d bit capacity '
            .'at QR Code Model 2 Version %d Error Correction Level %s. '
            .'Shorten the app URL or configure a short verification domain/path '
            .'(id_cards.qr.base_url).',
            self::CODE,
            mb_strlen($payload),
            $requiredBits,
            $capacityBits,
            $maximumVersion,
            $eccName,
        ));
    }
}
