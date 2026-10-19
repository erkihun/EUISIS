<?php

declare(strict_types=1);

use App\Services\Intelligence\Forecasting\Forecaster;

/*
 * The linear baseline (D-10): deterministic least squares, a residual band,
 * floor at zero, stated confidence and limitations, and nothing under six points.
 */

it('refuses to fit fewer than six points', function (): void {
    expect(Forecaster::linear([1, 2, 3, 4, 5]))->toBe(['points' => 5, 'insufficient' => true])
        ->and(Forecaster::linear([]))->toBe(['points' => 0, 'insufficient' => true]);
});

it('fits an exact line with a zero-width band and high confidence', function (): void {
    $fit = Forecaster::linear(range(1, 12), 3, '2026-09');

    expect($fit['method'])->toBe(Forecaster::METHOD)
        ->and($fit['slope'])->toBe(1.0)
        ->and($fit['intercept'])->toBe(1.0)
        ->and($fit['r2'])->toBe(1.0)
        ->and($fit['confidence'])->toBe('high')
        ->and(array_column($fit['forecast'], 'period'))->toBe(['2026-10', '2026-11', '2026-12'])
        ->and(array_column($fit['forecast'], 'value'))->toBe([13.0, 14.0, 15.0])
        ->and($fit['forecast'][0]['lower'])->toBe(13.0)
        ->and($fit['forecast'][0]['upper'])->toBe(13.0)
        ->and($fit['limitations'])->toBe(['linear_trend_only', 'no_seasonality', 'floored_at_zero']);
});

it('widens the band with noise, floors at zero and lowers confidence on a short noisy series', function (): void {
    $fit = Forecaster::linear([9, 2, 8, 1, 7, 0], 2);

    expect($fit['points'])->toBe(6)
        ->and($fit['slope'])->toBeLessThan(0)
        ->and($fit['confidence'])->toBe('low')
        ->and($fit['limitations'])->toContain('short_history')
        ->and($fit['forecast'][0]['lower'])->toBe(0.0)
        ->and($fit['forecast'][0]['upper'])->toBeGreaterThan($fit['forecast'][0]['value']);
});

it('treats a flat series as a perfect fit', function (): void {
    $fit = Forecaster::linear([4, 4, 4, 4, 4, 4, 4], 1);

    expect($fit['slope'])->toBe(0.0)->and($fit['r2'])->toBe(1.0)->and($fit['forecast'][0]['value'])->toBe(4.0);
});
