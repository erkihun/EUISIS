<?php

declare(strict_types=1);

use App\Services\Intelligence\Analysis\Baseline;
use App\Services\Intelligence\Analysis\TrendDetector;

it('describes a history with robust statistics', function (): void {
    $stats = Baseline::describe([10, 12, 11, 13, 12, 200]);

    expect($stats['n'])->toBe(6)
        ->and($stats['median'])->toBe(12.0)
        ->and($stats['mad'])->toBe(1.0)
        ->and($stats['p95'])->toBeGreaterThan(100.0);
});

it('calls a value abnormal only beyond the robust band and above the minimum count', function (): void {
    $history = [20, 22, 19, 21, 20, 23, 21, 22];

    expect(Baseline::isAbnormallyHigh(45, $history, sigma: 3))->toBeTrue()
        ->and(Baseline::isAbnormallyHigh(24, $history, sigma: 3))->toBeFalse()
        ->and(Baseline::isAbnormallyHigh(45, $history, sigma: 3, minimum: 50))->toBeFalse()
        ->and(Baseline::isAbnormallyLow(2, $history, sigma: 3))->toBeTrue()
        ->and(Baseline::zScore(45, [20, 21]))->toBeNull();
});

it('treats a first spike on a flat history as a deviation, not noise', function (): void {
    expect(Baseline::zScore(0, [0, 0, 0, 0, 0, 0]))->toBe(0.0)
        ->and(Baseline::zScore(7, [0, 0, 0, 0, 0, 0]))->toBe(10.0);
});

it('computes percentiles, moving averages and percent change', function (): void {
    expect(Baseline::percentile([1, 2, 3, 4, 5], 50))->toBe(3.0)
        ->and(Baseline::percentile([1, 2, 3, 4], 50))->toBe(2.5)
        ->and(Baseline::percentile([], 50))->toBeNull()
        ->and(Baseline::movingAverage([2, 4, 6, 8], 2))->toBe([null, 3.0, 5.0, 7.0])
        ->and(Baseline::changePercent(150, 100))->toBe(50.0)
        ->and(Baseline::changePercent(5, 0))->toBeNull();
});

it('detects a sustained rise, not a single spike', function (): void {
    $rising = TrendDetector::deteriorating([80, 180, 400, 850]);
    $spike = TrendDetector::deteriorating([80, 85, 400, 82]);
    $short = TrendDetector::deteriorating([80, 400]);

    expect($rising['deteriorating'])->toBeTrue()
        ->and($rising['slope'])->toBeGreaterThan(0)
        ->and($rising['increase_percent'])->toBeGreaterThan(900)
        ->and($rising['confidence'])->toBe('low')
        ->and($spike['deteriorating'])->toBeFalse()
        ->and($short['deteriorating'])->toBeFalse()
        ->and($short['points'])->toBe(2);

    $long = TrendDetector::deteriorating(range(100, 340, 20));
    expect($long['deteriorating'])->toBeTrue()->and($long['confidence'])->toBe('high');
});
