<?php

declare(strict_types=1);

namespace App\Services\Cafeteria\Policy;

/**
 * Applied amounts for one transaction, in minor units (cents) so no money
 * passes through a float. Invariant: subsidy + employee = provider total.
 */
final class CafeteriaPricing
{
    public function __construct(
        public readonly int $entitlementCount,
        public readonly int $subsidyCents,
        public readonly int $employeeCents,
        public readonly int $unitPriceCents,
        public readonly string $currency,
        public readonly bool $employeePaid,
    ) {}

    public function totalCents(): int
    {
        return $this->subsidyCents + $this->employeeCents;
    }

    public function subsidy(): string
    {
        return self::format($this->subsidyCents);
    }

    public function employeeAmount(): string
    {
        return self::format($this->employeeCents);
    }

    public function unitPrice(): string
    {
        return self::format($this->unitPriceCents);
    }

    public function total(): string
    {
        return self::format($this->totalCents());
    }

    public static function toCents(string|int|float|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        // Decimal strings from the database: split rather than multiply a float.
        $value = is_string($amount) ? trim($amount) : number_format((float) $amount, 2, '.', '');
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-+'), 2), 2, '0');
        $cents = ((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
