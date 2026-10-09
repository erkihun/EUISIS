<?php

declare(strict_types=1);

namespace App\Services\IdCards;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Common\Mode;
use chillerlan\QRCode\Common\Version;

/**
 * Chooses the QR symbol version for a card's verification URL.
 *
 * Version 6 is the floor rather than a fixed size: a payload that fits prints
 * at version 6, and a longer one steps up to the smallest version that holds
 * it. Error correction stays at Q throughout — the symbol grows to fit the
 * payload, the recovery level is never traded away to save space.
 *
 * A payload beyond the configured ceiling is reported rather than printed,
 * because a symbol large enough to hold anything would be too dense to scan
 * reliably from a card-sized print.
 */
final readonly class QrCodeVersionResolver
{
    public function __construct(private QrPayloadSecurityValidator $validator) {}

    /**
     * Resolve the symbol for a payload.
     *
     * @return array{version: int, ecc: string, model: int, capacity_bits: int, required_bits: int}
     *
     * @throws IdCardQrPayloadTooLongException when no allowed version fits
     * @throws QrPayloadContainsPiiException when the payload carries employee data
     */
    public function resolve(string $payload): array
    {
        $this->validator->assertSafe($payload);

        $ecc = new EccLevel($this->eccOrdinal());
        $minimum = $this->minimumVersion();
        $maximum = $this->maximumVersion();
        $required = $this->requiredBits($payload);

        // Without auto-upgrade the floor is also the ceiling, which is the
        // behaviour a fixed-version card specification asks for.
        $ceiling = config('id_cards.qr.auto_upgrade_version', true) ? $maximum : $minimum;

        for ($version = $minimum; $version <= $ceiling; $version++) {
            $capacity = $ecc->getMaxBitsForVersion(new Version($version));

            if ($required <= $capacity) {
                return [
                    'version' => $version,
                    'ecc' => $this->eccName(),
                    'model' => (int) config('id_cards.qr.model', 2),
                    'capacity_bits' => $capacity,
                    'required_bits' => $required,
                ];
            }
        }

        throw IdCardQrPayloadTooLongException::forPayload(
            $payload,
            $required,
            $ecc->getMaxBitsForVersion(new Version($ceiling)),
            $ceiling,
            $this->eccName(),
        );
    }

    /**
     * Bits this payload occupies in byte mode, including the mode indicator
     * and character-count header the symbol has to carry alongside the data.
     *
     * Byte mode is used unconditionally: the URL is UTF-8 and may contain
     * characters outside the alphanumeric set, so a narrower mode cannot be
     * assumed. Counting the header here matters — a payload that fits the raw
     * capacity can still overflow once the header is added.
     */
    public function requiredBits(string $payload): int
    {
        // 4-bit mode indicator + character count + 8 bits per byte. The count
        // field is 16 bits for versions 10 and up, 8 bits below that; the
        // wider field is assumed so the estimate is never optimistic.
        return 4 + 16 + (strlen($payload) * 8);
    }

    /** The floor, clamped to the versions the standard defines. */
    public function minimumVersion(): int
    {
        return max(1, min(40, (int) config('id_cards.qr.minimum_version', 6)));
    }

    /** The ceiling, never below the floor. */
    public function maximumVersion(): int
    {
        return max($this->minimumVersion(), min(40, (int) config('id_cards.qr.maximum_version', 12)));
    }

    /** Configured error correction level name, defaulting to Q. */
    public function eccName(): string
    {
        $name = strtoupper((string) config('id_cards.qr.default_error_correction', 'Q'));

        return in_array($name, ['L', 'M', 'Q', 'H'], true) ? $name : 'Q';
    }

    /** The library's ordinal for the configured level. */
    public function eccOrdinal(): int
    {
        return match ($this->eccName()) {
            'L' => EccLevel::L,
            'M' => EccLevel::M,
            'H' => EccLevel::H,
            default => EccLevel::Q,
        };
    }

    /** Byte mode is the encoding used for the UTF-8 verification URL. */
    public function mode(): int
    {
        return Mode::BYTE;
    }
}
