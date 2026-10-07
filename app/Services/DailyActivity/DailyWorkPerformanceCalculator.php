<?php

declare(strict_types=1);

namespace App\Services\DailyActivity;

use InvalidArgumentException;

/**
 * The performance formulas of the Employee Daily Plan & Work Execution form
 * (የባለሞያ የእለት እቅድ ክንውን መመዝገቢያ), exactly as the form states them:
 *
 *   Quantity performance (የመጠን አፈጻጸም) = Actual / Plan × 100
 *   Time performance     (የጊዜ አፈጻጸም)  = Plan / Actual × 100
 *   Quality performance  (የጥራት አፈጻጸም) = Actual / Plan × 100
 *   Main task aggregate  (ጥቅል አፈጻጸም)  = (Quantity + Time + Quality) / 3
 *   Sub-service aggregate               = (Task 1 + Task 2 + …) / number of tasks
 *
 * Decimal arithmetic only (bcmath), never float: these are official figures.
 * Intermediate values keep SCALE digits; only stored results are rounded, so
 * an aggregate is computed from unrounded components and rounding error does
 * not accumulate. Results are never capped at 100: finishing faster than the
 * planned time, or doing more than planned, legitimately scores above 100,
 * and no approved cap exists (docs/daily-work-register.md, NEEDS_DECISION).
 *
 * A denominator of zero is refused, never turned into Infinity or zero.
 */
final class DailyWorkPerformanceCalculator
{
    /** Digits kept in intermediate values. */
    public const SCALE = 10;

    /** Digits stored for a score (NUMERIC(12,4)). */
    public const STORED_SCALE = 4;

    /** The task aggregate averages whichever dimensions the standard measures. */
    public const RULE_APPLICABLE_AVERAGE = 'applicable_average';

    /** The task aggregate exists only when all three dimensions are measured. */
    public const RULE_ALL_THREE = 'all_three';

    public function quantityScore(mixed $actual, mixed $planned): string
    {
        return $this->ratio($actual, $planned, 'planned_quantity');
    }

    public function timeScore(mixed $plannedMinutes, mixed $actualMinutes): string
    {
        return $this->ratio($plannedMinutes, $actualMinutes, 'actual_time');
    }

    public function qualityScore(mixed $actual, mixed $planned): string
    {
        return $this->ratio($actual, $planned, 'planned_quality');
    }

    /**
     * The main task aggregate from the dimension scores that apply.
     *
     * @param  array<string, string|null>  $scores  applicable dimension => unrounded score, null when not yet recorded
     * @return string|null null when incomplete, or when the rule excludes a partial standard
     */
    public function taskScore(array $scores, string $rule = self::RULE_APPLICABLE_AVERAGE): ?string
    {
        if ($scores === [] || in_array(null, $scores, true)) {
            return null;
        }

        if ($rule === self::RULE_ALL_THREE && count($scores) !== 3) {
            return null;
        }

        return $this->mean(array_values($scores));
    }

    /**
     * The sub-service aggregate: the arithmetic mean of its task scores.
     *
     * @param  array<int, string>  $taskScores
     */
    public function subServiceScore(array $taskScores): ?string
    {
        return $taskScores === [] ? null : $this->mean($taskScores);
    }

    /** Round half up to the stored precision (scores are never negative). */
    public function round(string $value, int $scale = self::STORED_SCALE): string
    {
        return bcadd($value, '0.'.str_repeat('0', $scale).'5', $scale);
    }

    /** @param array<int, string> $values */
    private function mean(array $values): string
    {
        $sum = '0';
        foreach ($values as $value) {
            $sum = bcadd($sum, $this->decimal($value, 'score'), self::SCALE);
        }

        return bcdiv($sum, (string) count($values), self::SCALE);
    }

    private function ratio(mixed $numerator, mixed $denominator, string $denominatorName): string
    {
        $top = $this->decimal($numerator, 'value');
        $bottom = $this->decimal($denominator, $denominatorName);

        if (bccomp($bottom, '0', self::SCALE) === 0) {
            throw new InvalidArgumentException("Cannot calculate a score: {$denominatorName} is zero.");
        }

        return bcdiv(bcmul($top, '100', self::SCALE), $bottom, self::SCALE);
    }

    /** A plain, non-negative decimal string; floats are never trusted as-is. */
    private function decimal(mixed $value, string $name): string
    {
        if (is_float($value)) {
            $value = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        $text = trim((string) $value);

        if ($text === '' || ! is_numeric($text) || str_contains(strtolower($text), 'e')) {
            throw new InvalidArgumentException("Cannot calculate a score: {$name} is not a decimal number.");
        }

        if (bccomp($text, '0', self::SCALE) < 0) {
            throw new InvalidArgumentException("Cannot calculate a score: {$name} is negative.");
        }

        return $text;
    }
}
