<?php

declare(strict_types=1);

namespace App\Services\Performance\Calculation;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Decimal arithmetic for every EPMS score (docs/epms-calculation-rules.md).
 *
 * Never floats: 0.1 + 0.2 must be 0.3. Values travel as strings; results are
 * rounded HALF_UP to 4 places only when stored or shown, intermediate steps
 * keep 10 places. Division by zero returns null — callers decide what a
 * missing ratio means, it is never silently 0 or infinity.
 */
final class Dec
{
    public const SCALE = 4;

    private const WORK = 10;

    public static function of(mixed $value): ?BigDecimal
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof BigDecimal) {
            return $value;
        }
        if (is_bool($value)) {
            return BigDecimal::of($value ? 1 : 0);
        }
        if (is_float($value)) {
            // Floats are only accepted from literals; go through a fixed string.
            $value = number_format($value, self::WORK, '.', '');
        }

        return BigDecimal::of((string) $value);
    }

    public static function zero(): BigDecimal
    {
        return BigDecimal::zero();
    }

    public static function hundred(): BigDecimal
    {
        return BigDecimal::of(100);
    }

    public static function div(BigDecimal $a, BigDecimal $b): ?BigDecimal
    {
        return $b->isZero() ? null : $a->dividedBy($b, self::WORK, RoundingMode::HALF_UP);
    }

    public static function pct(BigDecimal $a, BigDecimal $b): ?BigDecimal
    {
        $ratio = self::div($a, $b);

        return $ratio?->multipliedBy(100);
    }

    public static function min(BigDecimal $a, BigDecimal $b): BigDecimal
    {
        return $a->isLessThan($b) ? $a : $b;
    }

    public static function max(BigDecimal $a, BigDecimal $b): BigDecimal
    {
        return $a->isGreaterThan($b) ? $a : $b;
    }

    /** Rounded string for storage / display (HALF_UP, 4 places). */
    public static function str(?BigDecimal $value, int $scale = self::SCALE): ?string
    {
        return $value?->toScale($scale, RoundingMode::HALF_UP)->__toString();
    }

    /** @param iterable<BigDecimal> $values */
    public static function sum(iterable $values): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($values as $value) {
            $total = $total->plus($value);
        }

        return $total;
    }
}
