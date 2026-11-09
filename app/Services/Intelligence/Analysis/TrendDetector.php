<?php

declare(strict_types=1);

namespace App\Services\Intelligence\Analysis;

/**
 * Detects a deteriorating trend in an ordered series (brief §33): a sustained
 * rise, not a single spike. The verdict carries its evidence (slope, the
 * share of rising steps, first and last value) and a confidence that reflects
 * the number of points, never a probability of failure.
 */
final class TrendDetector
{
    public const MIN_POINTS = 4;

    /**
     * Least-squares slope per step, or null below two points.
     *
     * @param  list<int|float>  $values
     */
    public static function slope(array $values): ?float
    {
        $values = array_values(array_filter($values, 'is_numeric'));
        $n = count($values);
        if ($n < 2) {
            return null;
        }
        $meanX = ($n - 1) / 2;
        $meanY = array_sum($values) / $n;
        $num = 0.0;
        $den = 0.0;
        foreach ($values as $x => $y) {
            $num += ($x - $meanX) * ((float) $y - $meanY);
            $den += ($x - $meanX) ** 2;
        }

        return $den == 0.0 ? null : round($num / $den, 4);
    }

    /**
     * Whether the series rises steadily: positive slope, the last value at
     * least `minimumIncreasePercent` above the first, and at least 70% of the
     * steps non-decreasing.
     *
     * @param  list<int|float>  $values  Oldest first.
     * @return array{deteriorating: bool, points: int, slope: ?float, first: ?float, last: ?float, increase_percent: ?float, rising_steps_share: ?float, confidence: ?string}
     */
    public static function deteriorating(array $values, float $minimumIncreasePercent = 50.0): array
    {
        $values = array_values(array_filter($values, 'is_numeric'));
        $n = count($values);
        $result = ['deteriorating' => false, 'points' => $n, 'slope' => null, 'first' => null, 'last' => null, 'increase_percent' => null, 'rising_steps_share' => null, 'confidence' => null];
        if ($n < self::MIN_POINTS) {
            return $result;
        }

        $first = (float) $values[0];
        $last = (float) $values[$n - 1];
        $rising = 0;
        for ($i = 1; $i < $n; $i++) {
            if ($values[$i] >= $values[$i - 1]) {
                $rising++;
            }
        }
        $share = round($rising / ($n - 1), 2);
        $slope = self::slope($values);
        $increase = Baseline::changePercent($last, $first);

        $result = [
            ...$result,
            'slope' => $slope,
            'first' => $first,
            'last' => $last,
            'increase_percent' => $increase,
            'rising_steps_share' => $share,
        ];

        if ($slope !== null && $slope > 0 && $share >= 0.7 && $increase !== null && $increase >= $minimumIncreasePercent) {
            $result['deteriorating'] = true;
            $result['confidence'] = $n >= 12 && $share >= 0.9 ? 'high' : ($n >= 6 ? 'medium' : 'low');
        }

        return $result;
    }
}
