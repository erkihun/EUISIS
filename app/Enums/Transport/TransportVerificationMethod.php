<?php

declare(strict_types=1);

namespace App\Enums\Transport;

/** How the card was read for a boarding. QR and NFC run through one decision engine. */
enum TransportVerificationMethod: string
{
    case Qr = 'qr';
    case Nfc = 'nfc';
    /** Recorded by an authorised person with a reason; never a silent count. */
    case ManualException = 'manual_exception';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
