<?php

declare(strict_types=1);

namespace App\Services\Transport\Settlement;

use InvalidArgumentException;

/**
 * Money as integer cents (ETB to two decimals). No floating point anywhere
 * in a settlement: amounts arrive as decimal strings, are summed and
 * multiplied as integers, and leave as decimal strings for DECIMAL(14,2)
 * columns. Rates are limited to two decimals as well; a finer rate is a
 * policy question, not a rounding one.
 */
final class TransportMoney
{
    public static function toCents(int|float|string|null $amount): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }
        $text = is_string($amount) ? trim($amount) : number_format((float) $amount, 2, '.', '');
        if (preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', $text, $m) !== 1) {
            throw new InvalidArgumentException("Not a two-decimal amount: {$text}");
        }
        $cents = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

        return ($m[1] ?? '') === '-' ? -$cents : $cents;
    }

    public static function format(?int $cents): ?string
    {
        if ($cents === null) {
            return null;
        }
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** rate × quantity, rate in cents. */
    public static function times(int $rateCents, int $quantity): int
    {
        return $rateCents * $quantity;
    }
}
