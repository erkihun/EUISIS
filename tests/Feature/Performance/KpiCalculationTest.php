<?php

declare(strict_types=1);

use App\Enums\Performance\KpiAggregation;
use App\Enums\Performance\KpiDirection;
use App\Services\Performance\Calculation\AchievementResult;
use App\Services\Performance\Calculation\KpiAchievementCalculator;
use App\Services\Performance\Calculation\KpiAggregationService;
use App\Services\Performance\Calculation\Observation;

/*
|--------------------------------------------------------------------------
| KPI formulas and aggregation (docs/epms-calculation-rules.md §2–3)
|--------------------------------------------------------------------------
*/

function kpiCalc(): KpiAchievementCalculator
{
    return new KpiAchievementCalculator;
}

function kpiAgg(): KpiAggregationService
{
    return new KpiAggregationService;
}

test('11 higher-is-better: actual ÷ target × 100', function (): void {
    expect(kpiCalc()->calculate(KpiDirection::HigherIsBetter, '200', '180', ['cap' => '120'])->achievement)->toBe('90.0000')
        ->and(kpiCalc()->calculate(KpiDirection::HigherIsBetter, '3', '1')->achievement)->toBe('33.3333');
});

test('12 lower-is-better: target ÷ actual × 100, zero actual is best', function (): void {
    expect(kpiCalc()->calculate(KpiDirection::LowerIsBetter, '5', '10', ['cap' => '120'])->achievement)->toBe('50.0000')
        ->and(kpiCalc()->calculate(KpiDirection::LowerIsBetter, '5', '4', ['cap' => '120'])->achievement)->toBe('120.0000')
        ->and(kpiCalc()->calculate(KpiDirection::LowerIsBetter, '5', '0', ['cap' => '110'])->achievement)->toBe('110.0000')
        ->and(kpiCalc()->calculate(KpiDirection::LowerIsBetter, '0', '3')->achievement)->toBe('0.0000');
});

test('13 target-is-best: full score within tolerance, linear to zero, never the higher-is-better formula', function (): void {
    $options = ['tolerance' => '2', 'zero_score_deviation' => '10'];

    expect(kpiCalc()->calculate(KpiDirection::TargetIsBest, '0', '1.5', $options)->achievement)->toBe('100.0000')
        ->and(kpiCalc()->calculate(KpiDirection::TargetIsBest, '0', '-6', $options)->achievement)->toBe('50.0000')
        ->and(kpiCalc()->calculate(KpiDirection::TargetIsBest, '0', '6', $options)->achievement)->toBe('50.0000')
        ->and(kpiCalc()->calculate(KpiDirection::TargetIsBest, '0', '15', $options)->achievement)->toBe('0.0000')
        // Over-delivering does not score above 100 for a target-is-best KPI.
        ->and(kpiCalc()->calculate(KpiDirection::TargetIsBest, '100', '100', ['cap' => '120'])->achievement)->toBe('100.0000');

    $invalid = kpiCalc()->calculate(KpiDirection::TargetIsBest, '0', '5');
    expect($invalid->achievement)->toBeNull()->and($invalid->status)->toBe(AchievementResult::INVALID_TARGET);
});

test('14 binary: done or not done', function (): void {
    expect(kpiCalc()->calculate(KpiDirection::Binary, null, '1')->achievement)->toBe('100.0000')
        ->and(kpiCalc()->calculate(KpiDirection::Binary, null, '0')->achievement)->toBe('0.0000');
});

test('15 milestone: percent of the reached milestone', function (): void {
    $milestones = [
        ['key' => 'draft', 'percent' => 20],
        ['key' => 'review', 'percent' => 50],
        ['key' => 'approved', 'percent' => 100, 'requires_verification' => true],
    ];

    expect(kpiCalc()->calculate(KpiDirection::Milestone, null, null, ['milestones' => $milestones, 'milestone_key' => 'review'])->achievement)->toBe('50.0000')
        ->and(kpiCalc()->calculate(KpiDirection::Milestone, null, null, ['milestones' => $milestones, 'milestone_key' => 'approved'])->achievement)->toBe('100.0000')
        ->and(kpiCalc()->calculate(KpiDirection::Milestone, null, null, ['milestones' => $milestones])->status)->toBe(AchievementResult::NO_ACTUAL);
});

test('19 division by zero and invalid values are safe and explicit, never a silent 0 or infinity', function (): void {
    $zeroTarget = kpiCalc()->calculate(KpiDirection::HigherIsBetter, '0', '5');
    $negativeActual = kpiCalc()->calculate(KpiDirection::HigherIsBetter, '10', '-1');
    $noActual = kpiCalc()->calculate(KpiDirection::HigherIsBetter, '10', null);

    expect($zeroTarget->achievement)->toBeNull()->and($zeroTarget->status)->toBe(AchievementResult::INVALID_TARGET)
        ->and($negativeActual->status)->toBe(AchievementResult::INVALID_ACTUAL)
        ->and($noActual->status)->toBe(AchievementResult::NO_ACTUAL)
        // A missing actual counts as 0 in a weighted score, and the trace says why.
        ->and($noActual->scoringValue())->toBe('0.0000')
        ->and(kpiAgg()->aggregate(KpiAggregation::RatioFromTotals, [new Observation(null, '3', '0')])->value)->toBeNull();
});

test('20 the achievement cap is enforced (KPI/target cap, 100 when over-achievement is off)', function (): void {
    $capped = kpiCalc()->calculate(KpiDirection::HigherIsBetter, '100', '1000', ['cap' => '120']);

    expect($capped->achievement)->toBe('120.0000')->and($capped->capped)->toBeTrue()->and($capped->rawAchievement)->toBe('1000.0000')
        ->and(kpiCalc()->calculate(KpiDirection::HigherIsBetter, '100', '150', ['cap' => '150', 'allow_overachievement' => false])->achievement)->toBe('100.0000')
        // A cap below 100 is not allowed to cut normal achievement.
        ->and(kpiCalc()->calculate(KpiDirection::HigherIsBetter, '100', '100', ['cap' => '50'])->achievement)->toBe('100.0000');
});

test('16 sum aggregation adds counts', function (): void {
    $rows = [new Observation('10', periodEnd: '2026-01-31'), new Observation('15', periodEnd: '2026-02-28'), new Observation('5', periodEnd: '2026-03-31')];

    expect(kpiAgg()->aggregate(KpiAggregation::Sum, $rows)->value)->toBe('30.0000');
});

test('17 weighted average is weighted by volume, not an average of averages', function (): void {
    // Unit A: 100 cases averaging 2 days; Unit B: 10 cases averaging 10 days.
    $rows = [new Observation('2', weight: '100'), new Observation('10', weight: '10')];

    expect(kpiAgg()->aggregate(KpiAggregation::WeightedAverage, $rows)->value)->toBe('2.7273')
        ->and(kpiAgg()->aggregate(KpiAggregation::Average, $rows)->value)->toBe('6.0000');
});

test('18 ratio from totals: Σ errors ÷ Σ cases, never a sum or average of percentages', function (): void {
    // 1/10 = 10% and 9/90 = 10% → 10/100 = 10%, whereas summing % would give 20%.
    $rows = [new Observation(null, '1', '10'), new Observation(null, '9', '90'), new Observation(null, '0', '100')];

    $result = kpiAgg()->aggregate(KpiAggregation::RatioFromTotals, $rows, percentage: true);
    expect($result->value)->toBe('5.0000')->and($result->numerator)->toBe('10.0000')->and($result->denominator)->toBe('200.0000');
});

test('combining children: milestones and custom formulas need their own actual; latest values add up', function (): void {
    $children = [kpiAgg()->aggregate(KpiAggregation::LatestValue, [new Observation('40', periodEnd: '2026-03-31')]), kpiAgg()->aggregate(KpiAggregation::LatestValue, [new Observation('60', periodEnd: '2026-03-31')])];

    expect(kpiAgg()->combine(KpiAggregation::LatestValue, $children)->value)->toBe('100.0000')
        ->and(kpiAgg()->combine(KpiAggregation::Milestone, $children)->value)->toBeNull()
        ->and(kpiAgg()->combine(KpiAggregation::CustomFormula, $children)->value)->toBeNull();
});

test('decimal math: no floating point drift', function (): void {
    $rows = array_fill(0, 10, new Observation('0.1'));

    expect(kpiAgg()->aggregate(KpiAggregation::Sum, $rows)->value)->toBe('1.0000');
});
