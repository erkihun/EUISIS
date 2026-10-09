<?php

declare(strict_types=1);

namespace App\Services\Performance\Calculation;

use App\Enums\Performance\KpiDirection;
use Brick\Math\BigDecimal;

/**
 * Achievement % of one KPI: actual measured against target, by direction.
 * The only place these formulas exist (docs/epms-calculation-rules.md §2).
 * Pure: no database, no settings lookups — callers pass the effective cap.
 *
 *   HIGHER_IS_BETTER  actual / target × 100               (target > 0)
 *   LOWER_IS_BETTER   target / actual × 100               (actual 0 → best)
 *   TARGET_IS_BEST    100 within ±tolerance, falling linearly to 0 at
 *                     ±zero_score_deviation (explicit, configured)
 *   BINARY            actual ≥ 1 → 100, else 0
 *   MILESTONE         percent of the reached milestone (or the value itself)
 *
 * Then: floor 0, ceiling = cap (100 when over-achievement is not allowed).
 * Never divides by zero; a value that cannot be computed is null with a
 * status explaining why, never a silent 0.
 */
final class KpiAchievementCalculator
{
    /**
     * @param  array{cap?: string|int|null, allow_overachievement?: bool, tolerance?: string|null, zero_score_deviation?: string|null, milestones?: array<int, array<string, mixed>>|null, milestone_key?: string|null}  $options
     */
    public function calculate(KpiDirection $direction, mixed $target, mixed $actual, array $options = []): AchievementResult
    {
        $cap = $this->effectiveCap($options);
        $capString = Dec::str($cap);

        if ($direction === KpiDirection::Milestone) {
            return $this->milestone($actual, $options, $cap, $capString);
        }

        $actualValue = Dec::of($actual);
        if ($actualValue === null) {
            return new AchievementResult(null, AchievementResult::NO_ACTUAL, 'no actual reported', cap: $capString);
        }

        if ($direction === KpiDirection::Binary) {
            $raw = $actualValue->isGreaterThanOrEqualTo(1) ? Dec::hundred() : Dec::zero();

            return $this->finish($raw, $cap, 'actual ≥ 1 → 100, otherwise 0');
        }

        $targetValue = Dec::of($target);
        if ($targetValue === null) {
            return new AchievementResult(null, AchievementResult::INVALID_TARGET, 'no target set', cap: $capString);
        }

        return match ($direction) {
            KpiDirection::HigherIsBetter => $this->higher($targetValue, $actualValue, $cap),
            KpiDirection::LowerIsBetter => $this->lower($targetValue, $actualValue, $cap),
            KpiDirection::TargetIsBest => $this->targetIsBest($targetValue, $actualValue, $options, $cap),
        };
    }

    private function higher(BigDecimal $target, BigDecimal $actual, BigDecimal $cap): AchievementResult
    {
        if (! $target->isPositive()) {
            return new AchievementResult(null, AchievementResult::INVALID_TARGET, 'higher-is-better needs a target above 0', cap: Dec::str($cap));
        }
        if ($actual->isNegative()) {
            return new AchievementResult(null, AchievementResult::INVALID_ACTUAL, 'actual cannot be negative', cap: Dec::str($cap));
        }

        return $this->finish(Dec::pct($actual, $target), $cap, 'actual ÷ target × 100');
    }

    private function lower(BigDecimal $target, BigDecimal $actual, BigDecimal $cap): AchievementResult
    {
        if ($target->isNegative()) {
            return new AchievementResult(null, AchievementResult::INVALID_TARGET, 'lower-is-better target cannot be negative', cap: Dec::str($cap));
        }
        if ($actual->isNegative()) {
            return new AchievementResult(null, AchievementResult::INVALID_ACTUAL, 'actual cannot be negative', cap: Dec::str($cap));
        }
        if ($actual->isZero()) {
            // Nothing is better than zero: target met in full (and capped).
            $raw = $target->isZero() ? Dec::hundred() : $cap;

            return $this->finish($raw, $cap, 'actual is 0 → best possible');
        }
        if ($target->isZero()) {
            return $this->finish(Dec::zero(), $cap, 'target 0 missed (actual above 0) → 0');
        }

        return $this->finish(Dec::pct($target, $actual), $cap, 'target ÷ actual × 100');
    }

    /** @param array<string, mixed> $options */
    private function targetIsBest(BigDecimal $target, BigDecimal $actual, array $options, BigDecimal $cap): AchievementResult
    {
        $tolerance = Dec::of($options['tolerance'] ?? null) ?? Dec::zero();
        $zeroAt = Dec::of($options['zero_score_deviation'] ?? null) ?? ($target->isZero() ? null : $target->abs());

        if ($tolerance->isNegative() || $zeroAt === null || ! $zeroAt->isGreaterThan($tolerance)) {
            return new AchievementResult(null, AchievementResult::INVALID_TARGET, 'target-is-best needs zero_score_deviation greater than tolerance', cap: Dec::str($cap));
        }

        $deviation = $actual->minus($target)->abs();
        if ($deviation->isLessThanOrEqualTo($tolerance)) {
            return $this->finish(Dec::hundred(), $cap, '|actual − target| within tolerance → 100');
        }

        // 100 × (1 − (deviation − tolerance) ÷ (zero_score_deviation − tolerance)), floored at 0.
        $share = Dec::div($deviation->minus($tolerance), $zeroAt->minus($tolerance)) ?? Dec::zero();
        $raw = Dec::max(Dec::zero(), Dec::hundred()->multipliedBy(BigDecimal::one()->minus($share)));

        return $this->finish($raw, $cap, '100 × (1 − (|actual − target| − tolerance) ÷ (zero_score_deviation − tolerance))');
    }

    /** @param array<string, mixed> $options */
    private function milestone(mixed $actual, array $options, BigDecimal $cap, ?string $capString): AchievementResult
    {
        $key = $options['milestone_key'] ?? null;
        if (is_string($key) && $key !== '') {
            foreach ((array) ($options['milestones'] ?? []) as $milestone) {
                if (($milestone['key'] ?? null) === $key) {
                    return $this->finish(Dec::of($milestone['percent'] ?? 0) ?? Dec::zero(), $cap, "milestone '{$key}' reached → its configured percent");
                }
            }

            return new AchievementResult(null, AchievementResult::INVALID_ACTUAL, "unknown milestone '{$key}'", cap: $capString);
        }

        $value = Dec::of($actual);
        if ($value === null) {
            return new AchievementResult(null, AchievementResult::NO_ACTUAL, 'no milestone reported', cap: $capString);
        }

        return $this->finish($value, $cap, 'milestone completion % as reported');
    }

    private function finish(?BigDecimal $raw, BigDecimal $cap, string $formula): AchievementResult
    {
        if ($raw === null) {
            return new AchievementResult(null, AchievementResult::INVALID_TARGET, 'division by zero avoided', cap: Dec::str($cap));
        }

        $floored = Dec::max(Dec::zero(), $raw);
        $capped = $floored->isGreaterThan($cap);
        $value = $capped ? $cap : $floored;

        return new AchievementResult(Dec::str($value), AchievementResult::OK, $formula, Dec::str($raw), Dec::str($cap), $capped);
    }

    /** @param array<string, mixed> $options */
    private function effectiveCap(array $options): BigDecimal
    {
        if (($options['allow_overachievement'] ?? true) === false) {
            return Dec::hundred();
        }

        $cap = Dec::of($options['cap'] ?? null);

        return $cap !== null && $cap->isGreaterThanOrEqualTo(Dec::hundred()) ? $cap : Dec::hundred();
    }
}
