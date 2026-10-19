<?php

declare(strict_types=1);

namespace App\Services\Intelligence\Forecasting;

use Carbon\CarbonImmutable;

/**
 * The only forecasting method of phase 2 (docs/intelligence-architecture.md,
 * D-10): an ordinary least-squares line through a monthly series, extended
 * a few months ahead with a residual-based prediction band. Pure arithmetic,
 * no model, no randomness: the same series always yields the same forecast,
 * and every number in the result can be recomputed by hand.
 *
 * Under six monthly points no line is fitted: the caller shows
 * FORECAST_DATA_INSUFFICIENT instead of a guess.
 */
final class Forecaster
{
    public const METHOD = 'least_squares_linear';

    public const MIN_POINTS = 6;

    /** 97.5th percentile of the standard normal: a 95% band. */
    private const Z = 1.96;

    /**
     * @param  list<int|float>  $monthlySeries  Oldest first, one value per month.
     * @param  int  $horizon  Months ahead to forecast.
     * @param  string|null  $lastPeriod  'Y-m' of the last point; forecast periods follow it when given.
     * @return array{points: int, insufficient?: bool, method?: string, slope?: float, intercept?: float, r2?: float, residual_sd?: float, forecast?: list<array{period: string, value: float, lower: float, upper: float}>, confidence?: 'high'|'medium'|'low', limitations?: list<string>}
     */
    public static function linear(array $monthlySeries, int $horizon = 3, ?string $lastPeriod = null): array
    {
        $values = array_values(array_map(fn ($v): float => (float) $v, array_filter($monthlySeries, 'is_numeric')));
        $n = count($values);
        if ($n < self::MIN_POINTS) {
            return ['points' => $n, 'insufficient' => true];
        }

        $meanX = ($n - 1) / 2;
        $meanY = array_sum($values) / $n;
        $sxx = 0.0;
        $sxy = 0.0;
        $syy = 0.0;
        foreach ($values as $x => $y) {
            $sxx += ($x - $meanX) ** 2;
            $sxy += ($x - $meanX) * ($y - $meanY);
            $syy += ($y - $meanY) ** 2;
        }
        $slope = $sxx == 0.0 ? 0.0 : $sxy / $sxx;
        $intercept = $meanY - $slope * $meanX;

        $sse = 0.0;
        foreach ($values as $x => $y) {
            $sse += ($y - ($intercept + $slope * $x)) ** 2;
        }
        // A flat series fits itself perfectly; otherwise the share of variance the line explains.
        $r2 = $syy == 0.0 ? 1.0 : max(0.0, 1 - $sse / $syy);
        $residualSd = sqrt($sse / ($n - 2));

        $forecast = [];
        $start = $lastPeriod !== null && preg_match('/^\d{4}-\d{2}$/', $lastPeriod) === 1
            ? CarbonImmutable::createFromFormat('!Y-m', $lastPeriod)
            : null;
        for ($h = 1; $h <= max(1, $horizon); $h++) {
            $x = $n - 1 + $h;
            $point = $intercept + $slope * $x;
            // The prediction band widens with the distance from the centre of the history.
            $halfWidth = self::Z * $residualSd * sqrt(1 + 1 / $n + (($x - $meanX) ** 2) / ($sxx == 0.0 ? 1 : $sxx));
            $forecast[] = [
                'period' => $start === null ? "+{$h}" : $start->addMonthsNoOverflow($h)->format('Y-m'),
                // Counts cannot go below zero, so the line and the band are floored there.
                'value' => round(max(0.0, $point), 2),
                'lower' => round(max(0.0, $point - $halfWidth), 2),
                'upper' => round(max(0.0, $point + $halfWidth), 2),
            ];
        }

        $limitations = ['linear_trend_only', 'no_seasonality', 'floored_at_zero'];
        if ($n < 12) {
            $limitations[] = 'short_history';
        }
        if ($r2 < 0.4) {
            $limitations[] = 'low_fit';
        }

        return [
            'points' => $n,
            'method' => self::METHOD,
            'slope' => round($slope, 4),
            'intercept' => round($intercept, 4),
            'r2' => round($r2, 4),
            'residual_sd' => round($residualSd, 4),
            'forecast' => $forecast,
            'confidence' => self::confidence($n, $r2),
            'limitations' => $limitations,
        ];
    }

    /** More history and a better fit mean more confidence; never "high" on a short or noisy series. */
    private static function confidence(int $points, float $r2): string
    {
        return match (true) {
            $points >= 12 && $r2 >= 0.7 => 'high',
            $points >= 9 && $r2 >= 0.4 => 'medium',
            default => 'low',
        };
    }
}
