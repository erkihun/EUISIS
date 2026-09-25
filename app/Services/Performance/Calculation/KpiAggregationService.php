<?php

declare(strict_types=1);

namespace App\Services\Performance\Calculation;

use App\Enums\Performance\KpiAggregation;
use Brick\Math\BigDecimal;

/**
 * How KPI values combine (docs/epms-calculation-rules.md §3). Two distinct
 * operations, both driven by the KPI's aggregation_method — never a blanket
 * SUM of percentages:
 *
 *   aggregate()  one subject's actuals across periods (months → year)
 *   combine()    several contributors (employees / child units) → parent
 *
 * Percent/ratio KPIs are recomputed from totals (Σnumerator ÷ Σdenominator),
 * averages are weighted by their weight (e.g. case count), never averaged
 * averages. MILESTONE, NO_AGGREGATION and CUSTOM_FORMULA cannot be combined
 * from children: the parent needs its own verified actual.
 */
final class KpiAggregationService
{
    public function canCombineChildren(KpiAggregation $method): bool
    {
        return ! in_array($method, [KpiAggregation::Milestone, KpiAggregation::NoAggregation, KpiAggregation::CustomFormula], true);
    }

    /** @param list<Observation> $observations */
    public function aggregate(KpiAggregation $method, array $observations, bool $percentage = false): AggregateValue
    {
        if ($observations === []) {
            return new AggregateValue(null, $method->value, 'no observations');
        }

        return match ($method) {
            KpiAggregation::Sum => $this->sum($method, $observations),
            KpiAggregation::Average => $this->average($method, $observations),
            KpiAggregation::WeightedAverage => $this->weightedAverage($method, $observations),
            KpiAggregation::RatioFromTotals => $this->ratio($method, $observations, $percentage),
            KpiAggregation::Min => $this->extreme($method, $observations, min: true),
            KpiAggregation::Max => $this->extreme($method, $observations, min: false),
            KpiAggregation::LatestValue, KpiAggregation::Milestone, KpiAggregation::NoAggregation, KpiAggregation::CustomFormula => $this->latest($method, $observations),
        };
    }

    /** @param list<AggregateValue> $children */
    public function combine(KpiAggregation $method, array $children, bool $percentage = false): AggregateValue
    {
        if (! $this->canCombineChildren($method)) {
            return new AggregateValue(null, $method->value, 'not combinable from contributors: the parent needs its own verified actual');
        }

        $observations = array_map(static fn (AggregateValue $child): Observation => $child->toObservation(''), $children);
        if ($observations === []) {
            return new AggregateValue(null, $method->value, 'no contributors');
        }

        // A stock measure ("as of" value) held by each contributor adds up.
        if ($method === KpiAggregation::LatestValue) {
            $result = $this->sum(KpiAggregation::Sum, $observations);

            return new AggregateValue($result->value, $method->value, 'Σ contributors\' latest values', $result->count, $result->numerator, $result->denominator, null, null, $result->periodEnd);
        }

        return $this->aggregate($method, $observations, $percentage);
    }

    /** Value of one observation: its own value, or numerator ÷ denominator. */
    public function valueOf(Observation $observation, bool $percentage = false): ?BigDecimal
    {
        $value = Dec::of($observation->value);
        if ($value !== null) {
            return $value;
        }

        $numerator = Dec::of($observation->numerator);
        $denominator = Dec::of($observation->denominator);
        if ($numerator === null || $denominator === null) {
            return null;
        }

        return $percentage ? Dec::pct($numerator, $denominator) : Dec::div($numerator, $denominator);
    }

    /** @param list<Observation> $observations */
    private function sum(KpiAggregation $method, array $observations): AggregateValue
    {
        $values = $this->values($observations);
        [$numerator, $denominator] = $this->totals($observations);

        return new AggregateValue(Dec::str(Dec::sum($values)), $method->value, 'Σ values', count($values), $numerator, $denominator, null, null, $this->lastPeriod($observations));
    }

    /** @param list<Observation> $observations */
    private function average(KpiAggregation $method, array $observations): AggregateValue
    {
        $values = $this->values($observations);
        $mean = $values === [] ? null : Dec::div(Dec::sum($values), BigDecimal::of(count($values)));

        return new AggregateValue(Dec::str($mean), $method->value, 'Σ values ÷ count', count($values), null, null, (string) count($values), null, $this->lastPeriod($observations));
    }

    /** @param list<Observation> $observations */
    private function weightedAverage(KpiAggregation $method, array $observations): AggregateValue
    {
        $weighted = Dec::zero();
        $weights = Dec::zero();
        $count = 0;
        foreach ($observations as $observation) {
            $value = $this->valueOf($observation);
            if ($value === null) {
                continue;
            }
            $weight = Dec::of($observation->weight) ?? Dec::of($observation->denominator) ?? BigDecimal::one();
            $weighted = $weighted->plus($value->multipliedBy($weight));
            $weights = $weights->plus($weight);
            $count++;
        }

        return new AggregateValue(Dec::str(Dec::div($weighted, $weights)), $method->value, 'Σ(value × weight) ÷ Σ weight', $count, null, null, Dec::str($weights), null, $this->lastPeriod($observations));
    }

    /** @param list<Observation> $observations */
    private function ratio(KpiAggregation $method, array $observations, bool $percentage): AggregateValue
    {
        [$numerator, $denominator] = $this->totals($observations);
        if ($numerator === null || $denominator === null) {
            return new AggregateValue(null, $method->value, 'ratio needs numerator and denominator on every actual', count($observations));
        }

        $value = $percentage ? Dec::pct(BigDecimal::of($numerator), BigDecimal::of($denominator)) : Dec::div(BigDecimal::of($numerator), BigDecimal::of($denominator));

        return new AggregateValue(Dec::str($value), $method->value, $percentage ? 'Σ numerator ÷ Σ denominator × 100' : 'Σ numerator ÷ Σ denominator', count($observations), $numerator, $denominator, null, null, $this->lastPeriod($observations));
    }

    /** @param list<Observation> $observations */
    private function extreme(KpiAggregation $method, array $observations, bool $min): AggregateValue
    {
        $values = $this->values($observations);
        $pick = null;
        foreach ($values as $value) {
            $pick = $pick === null ? $value : ($min ? Dec::min($pick, $value) : Dec::max($pick, $value));
        }

        return new AggregateValue(Dec::str($pick), $method->value, $min ? 'minimum value' : 'maximum value', count($values), null, null, null, null, $this->lastPeriod($observations));
    }

    /** @param list<Observation> $observations */
    private function latest(KpiAggregation $method, array $observations): AggregateValue
    {
        usort($observations, static fn (Observation $a, Observation $b): int => strcmp($a->periodEnd, $b->periodEnd));
        $last = end($observations);

        return new AggregateValue(
            Dec::str($this->valueOf($last)),
            $method->value,
            'latest period value',
            count($observations),
            $last->numerator,
            $last->denominator,
            $last->weight,
            $last->milestoneKey,
            $last->periodEnd,
        );
    }

    /**
     * @param  list<Observation>  $observations
     * @return list<BigDecimal>
     */
    private function values(array $observations): array
    {
        return array_values(array_filter(array_map(fn (Observation $o): ?BigDecimal => $this->valueOf($o), $observations)));
    }

    /**
     * @param  list<Observation>  $observations
     * @return array{0: ?string, 1: ?string}
     */
    private function totals(array $observations): array
    {
        $numerator = Dec::zero();
        $denominator = Dec::zero();
        foreach ($observations as $observation) {
            $n = Dec::of($observation->numerator);
            $d = Dec::of($observation->denominator);
            if ($n === null || $d === null) {
                return [null, null];
            }
            $numerator = $numerator->plus($n);
            $denominator = $denominator->plus($d);
        }

        return [Dec::str($numerator), Dec::str($denominator)];
    }

    /** @param list<Observation> $observations */
    private function lastPeriod(array $observations): string
    {
        return (string) max(array_map(static fn (Observation $o): string => $o->periodEnd, $observations));
    }
}
