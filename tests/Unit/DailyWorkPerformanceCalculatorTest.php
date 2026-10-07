<?php

declare(strict_types=1);

use App\Services\DailyActivity\DailyWorkPerformanceCalculator;

/*
 * The formulas of the Employee Daily Plan & Work Execution form, with the
 * worked examples from the specification.
 */

beforeEach(function (): void {
    $this->calc = new DailyWorkPerformanceCalculator;
});

it('calculates quantity, time and quality performance exactly as the form defines them', function (): void {
    // Quantity: Actual / Plan × 100 → 8 / 10 = 80%
    expect($this->calc->round($this->calc->quantityScore('8', '10')))->toBe('80.0000');
    // Time: Plan / Actual × 100 → 60 / 75 = 80%
    expect($this->calc->round($this->calc->timeScore('60', 75)))->toBe('80.0000');
    // Quality: Actual / Plan × 100 → 90 / 100 = 90%
    expect($this->calc->round($this->calc->qualityScore('90', '100')))->toBe('90.0000');
});

it('averages the three dimensions into the main task aggregate without rounding the parts first', function (): void {
    $scores = [
        'quantity' => $this->calc->quantityScore('8', '10'),
        'time' => $this->calc->timeScore('60', 75),
        'quality' => $this->calc->qualityScore('90', '100'),
    ];

    // (80 + 80 + 90) / 3 = 83.333…
    expect($this->calc->round($this->calc->taskScore($scores)))->toBe('83.3333')
        ->and($this->calc->round($this->calc->taskScore($scores), 2))->toBe('83.33');

    // Unrounded parts: 1/3 + 1/3 + 1/3 stays 33.3333, not 33.3333 × 3 = 99.9999.
    $thirds = ['quantity' => $this->calc->quantityScore('1', '3'), 'time' => $this->calc->timeScore('1', 3), 'quality' => $this->calc->qualityScore('1', '3')];
    expect($this->calc->round($this->calc->taskScore($thirds)))->toBe('33.3333');
});

it('does not cap a score above 100', function (): void {
    // Finished in half the planned time: 60 / 30 = 200%.
    expect($this->calc->round($this->calc->timeScore('60', 30)))->toBe('200.0000')
        ->and($this->calc->round($this->calc->quantityScore('15', '10')))->toBe('150.0000');
});

it('refuses a zero denominator instead of producing Infinity or zero', function (string $method, array $arguments): void {
    expect(fn () => $this->calc->{$method}(...$arguments))->toThrow(InvalidArgumentException::class);
})->with([
    'plan quantity 0' => ['quantityScore', ['5', '0']],
    'actual time 0' => ['timeScore', ['60', 0]],
    'plan quality 0' => ['qualityScore', ['90', '0']],
]);

it('refuses negative or non-numeric values', function (): void {
    expect(fn () => $this->calc->quantityScore('-1', '10'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->calc->quantityScore('1e3', '10'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->calc->quantityScore('abc', '10'))->toThrow(InvalidArgumentException::class);
});

it('averages only the measured dimensions, or none, according to the rule', function (): void {
    $two = ['quantity' => '80', 'time' => '100'];

    expect($this->calc->taskScore($two, DailyWorkPerformanceCalculator::RULE_APPLICABLE_AVERAGE))->toBe('90.0000000000')
        ->and($this->calc->taskScore($two, DailyWorkPerformanceCalculator::RULE_ALL_THREE))->toBeNull()
        // A measured dimension with no value yet is incomplete, never zero.
        ->and($this->calc->taskScore(['quantity' => '80', 'quality' => null]))->toBeNull();
});

it('averages task scores into the sub-service aggregate', function (): void {
    expect($this->calc->round($this->calc->subServiceScore(['80', '90', '100'])))->toBe('90.0000')
        ->and($this->calc->subServiceScore([]))->toBeNull();
});
