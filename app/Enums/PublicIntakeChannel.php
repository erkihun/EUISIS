<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a public submission reached the institution — the "how was the
 * Tip-off received" box of the paper intake form, recorded by the system
 * instead of asked of the citizen.
 *
 * Every submission through the public QR pages is WEB_QR. Phone, oral and
 * written intake are the paper form's other channels; no staff intake screen
 * exists yet, so nothing writes them today. They are listed so a future
 * internal intake records the same codes the paper form used.
 */
enum PublicIntakeChannel: string
{
    case WebQr = 'WEB_QR';
    case Phone = 'PHONE';
    case Oral = 'ORAL';
    case Written = 'WRITTEN';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
