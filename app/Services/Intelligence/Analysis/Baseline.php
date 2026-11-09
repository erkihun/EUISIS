<?php

declare(strict_types=1);

namespace App\Services\Intelligence\Analysis;

/**
 * Explainable baseline statistics for anomaly detection (brief §31–32).
 *
 * Robust by default: the median and the median absolute deviation (MAD)
 * describe the expected band, so a few extreme points in the history do not
 * hide a real deviation or invent one. A value is called abnormal only when
 * it sits `sigma` robust deviations from the median AND clears a minimum
 * count, so ordinary variation on small numbers is never an anomaly.
 */
final class Baseline
{
    /** Scales MAD to the standard deviation of a normal distribution. */
    private const MAD_SCALE = 0.6745;

    /** Below this many history points no band is estimated. */
    public const MIN_POINTS = 5;

    /**
     * @param  list<int|float>  $history
     * @return array{n: int, median: ?float, mad: ?float, mean: ?float, p95: ?float}
     */
    public static function describe(array $history): array
    {
        $values = array_values(array_filter($history, 'is_numeric'));
        $n = count($values);
        if ($n === 0) {
            return ['n' => 0, 'median' => null, 'mad' => null, 'mean' => null, 'p95' => null];
        }
        $median = self::percentile($values, 50);
        $deviations = array_map(fn (int|float $v): float => abs((float) $v - $median), $values);

        return [
            'n' => $n,
            'median' => $median,
            'mad' => self::percentile($deviations, 50),
            'mean' => array_sum($values) / $n,
            'p95' => self::percentile($values, 95),
        ];
    }

    /**
     * Robust z-score of a value against the history, or null when the history
     * is too short to say anything.
     *
     * With a zero MAD (a flat history) any different value is reported as a
     * large deviation (±10) so a first-ever spike on a quiet series is still
     * seen, and an identical value as 0.
     *
     * @param  list<int|float>  $history
     */
    public static function zScore(int|float $value, array $history): ?float
    {
        $stats = self::describe($history);
        if ($stats['n'] < self::MIN_POINTS || $stats['median'] === null) {
            return null;
        }
        if ($stats['mad'] === null || $stats['mad'] == 0.0) {
            return $value == $stats['median'] ? 0.0 : ($value > $stats['median'] ? 10.0 : -10.0);
        }

        return round(self::MAD_SCALE * ((float) $value - $stats['median']) / $stats['mad'], 2);
    }

    /**
     * Whether a value is abnormally HIGH: at least `sigma` robust deviations
     * above the median, and at least `minimum` in absolute terms.
     *
     * @param  list<int|float>  $history
     */
    public static function isAbnormallyHigh(int|float $value, array $history, int $sigma = 3, int|float $minimum = 0): bool
    {
        if ($value < $minimum) {
            return false;
        }
        $z = self::zScore($value, $history);

        return $z !== null && $z >= $sigma;
    }

    /** @param  list<int|float>  $history */
    public static function isAbnormallyLow(int|float $value, array $history, int $sigma = 3): bool
    {
        $z = self::zScore($value, $history);

        return $z !== null && $z <= -$sigma;
    }

    /** Linear-interpolated percentile (0–100) of numeric values. @param  list<int|float>  $values */
    public static function percentile(array $values, float $p): ?float
    {
        $sorted = array_values(array_filter($values, 'is_numeric'));
        if ($sorted === []) {
            return null;
        }
        sort($sorted);
        $n = count($sorted);
        if ($n === 1) {
            return (float) $sorted[0];
        }
        $rank = max(0.0, min(100.0, $p)) / 100 * ($n - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        $fraction = $rank - $low;

        return round((float) $sorted[$low] + ($sorted[$high] - $sorted[$low]) * $fraction, 4);
    }

    /**
     * Trailing moving average; the first `window - 1` points are null.
     *
     * @param  list<int|float>  $values
     * @return list<?float>
     */
    public static function movingAverage(array $values, int $window): array
    {
        $window = max(1, $window);
        $out = [];
        foreach (array_values($values) as $i => $value) {
            if ($i + 1 < $window) {
                $out[] = null;

                continue;
            }
            $slice = array_slice($values, $i + 1 - $window, $window);
            $out[] = round(array_sum($slice) / $window, 4);
        }

        return $out;
    }

    /** Percent change from previous to current, or null when the previous value is zero or unknown. */
    public static function changePercent(int|float|null $current, int|float|null $previous): ?float
    {
        if ($current === null || $previous === null || $previous == 0) {
            return null;
        }

        return round(100 * ((float) $current - (float) $previous) / (float) $previous, 1);
    }
}
