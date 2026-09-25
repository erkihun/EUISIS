<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Enums\Performance\KpiMeasurementType;
use App\Models\EmployeePerformanceAgreement;
use App\Models\EmployeePerformanceItem;
use App\Models\KpiActual;
use App\Models\PerformanceRatingBand;
use App\Models\PerformanceRatingScale;
use App\Services\Performance\Calculation\Dec;
use App\Services\Performance\Calculation\KpiAchievementCalculator;
use App\Services\Performance\Calculation\KpiAggregationService;
use App\Services\Performance\Calculation\Observation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The employee score, as a full trace (docs/epms-calculation-rules.md §4):
 *
 *   per item      actual = aggregate(item's own-source actuals over the
 *                 agreement period) → achievement % (capped) → × weight ÷ 100
 *   results       Σ weighted item achievements          (item weights = 100)
 *   competency    Σ(rating ÷ scale max × 100 × weight) ÷ Σ weight
 *   final         results × Rw ÷ 100 + competency × Cw ÷ 100   (Rw + Cw = 100)
 *   rating        band of the default RESULT scale containing the final score
 *
 * Daily activity only reaches this through an item whose data source is
 * DAILY_ACTIVITY, as an actual; the number of activities never counts.
 * Everything is Decimal; the returned trace is what gets frozen at finalization.
 */
final class EmployeeScoreCalculator
{
    public function __construct(
        private readonly KpiAchievementCalculator $achievement,
        private readonly KpiAggregationService $aggregation,
        private readonly EpmsSettings $settings,
    ) {}

    /** @return array<string, mixed> */
    public function trace(EmployeePerformanceAgreement $agreement): array
    {
        $weights = $this->settings->componentWeights();
        $items = $agreement->items()->with('kpi')->get();

        $itemRows = [];
        $results = Dec::zero();
        foreach ($items as $item) {
            $row = $this->item($item, $agreement);
            $results = $results->plus(BigDecimal::of($row['weighted']));
            $itemRows[] = $row;
        }

        [$competencyScore, $competencyRows, $competencyComplete] = $this->competency($agreement);

        $final = $results->multipliedBy($weights['results'])->dividedBy(100, 10, RoundingMode::HALF_UP);
        if ($weights['competency'] > 0 && $competencyScore !== null) {
            $final = $final->plus($competencyScore->multipliedBy($weights['competency'])->dividedBy(100, 10, RoundingMode::HALF_UP));
        }

        $band = $this->band($final);

        return [
            'formula' => [
                'item' => 'achievement % × item weight ÷ 100',
                'results' => 'Σ weighted item achievements',
                'competency' => 'Σ(rating ÷ scale max × 100 × weight) ÷ Σ weight',
                'final' => 'results × results_weight ÷ 100 + competency × competency_weight ÷ 100',
            ],
            'agreement_id' => $agreement->getKey(),
            'period' => [$agreement->effective_from?->toDateString(), $agreement->effective_to?->toDateString()],
            'items' => $itemRows,
            'results_score' => Dec::str($results),
            'competencies' => $competencyRows,
            'competency_score' => Dec::str($competencyScore),
            'competency_complete' => $competencyComplete,
            'results_weight' => (string) $weights['results'],
            'competency_weight' => (string) $weights['competency'],
            'results_contribution' => Dec::str($results->multipliedBy($weights['results'])->dividedBy(100, 10, RoundingMode::HALF_UP)),
            'competency_contribution' => Dec::str($competencyScore?->multipliedBy($weights['competency'])->dividedBy(100, 10, RoundingMode::HALF_UP)),
            'final_score' => Dec::str($final),
            'rating' => $band,
            'complete' => $competencyComplete && collect($itemRows)->every(fn ($row) => $row['achievement_status'] !== 'NO_ACTUAL'),
            'calculated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function item(EmployeePerformanceItem $item, EmployeePerformanceAgreement $agreement): array
    {
        $kpi = $item->kpi;
        $percentage = $kpi->measurement_type === KpiMeasurementType::Percentage;

        // The item's amendment chain shares one measurement history.
        $chain = [$item->getKey()];
        $previous = $item->supersedes_item_id;
        while ($previous !== null && ! in_array($previous, $chain, true)) {
            $chain[] = $previous;
            $previous = EmployeePerformanceItem::query()->whereKey($previous)->value('supersedes_item_id');
        }

        $actuals = KpiActual::query()
            ->whereIn('employee_performance_item_id', $chain)
            ->where('source_type', $item->data_source_type->value)
            ->where('period_start', '>=', $agreement->effective_from->toDateString())
            ->where('period_end', '<=', $agreement->effective_to->toDateString())
            ->orderBy('period_end')
            ->get();

        $observations = $actuals->map(fn (KpiActual $a): Observation => new Observation(
            $a->actual_value, $a->actual_numerator, $a->actual_denominator, null, $a->period_end->toDateString(), $a->milestone_key,
        ))->all();

        $aggregate = $this->aggregation->aggregate($kpi->aggregation_method, $observations, $percentage);
        $target = $item->target_value ?? $this->ratio($item->target_numerator, $item->target_denominator, $percentage);
        $cap = $item->achievement_cap ?? $item->positionTarget?->achievement_cap ?? $kpi->achievement_cap ?? $this->settings->defaultAchievementCap();

        $achievement = $this->achievement->calculate($kpi->direction, $target, $aggregate->value, [
            'cap' => (string) $cap,
            'allow_overachievement' => (bool) $kpi->allow_overachievement,
            'tolerance' => $item->tolerance ?? $kpi->target_tolerance,
            'zero_score_deviation' => $item->zero_score_deviation ?? $kpi->zero_score_deviation,
            'milestones' => $kpi->milestones,
            'milestone_key' => $aggregate->milestoneKey,
        ]);

        $weighted = BigDecimal::of($achievement->scoringValue())->multipliedBy(Dec::of($item->weight))->dividedBy(100, 10, RoundingMode::HALF_UP);

        return [
            'item_id' => $item->getKey(),
            'kpi_id' => $kpi->getKey(),
            'kpi_code' => $kpi->code,
            'kpi_name_en' => $kpi->name_en,
            'kpi_name_am' => $kpi->name_am,
            'direction' => $kpi->direction->value,
            'aggregation' => $aggregate->toArray(),
            'data_source' => $item->data_source_type->value,
            'actual_rows' => $actuals->count(),
            'target' => $target,
            'actual' => $aggregate->value,
            'weight' => Dec::str(Dec::of($item->weight)),
            'achievement' => $achievement->achievement,
            'achievement_status' => $achievement->status,
            'achievement_formula' => $achievement->formula,
            'raw_achievement' => $achievement->rawAchievement,
            'cap' => $achievement->cap,
            'capped' => $achievement->capped,
            'weighted' => Dec::str($weighted),
            'amended' => count($chain) > 1,
        ];
    }

    /** @return array{0: ?BigDecimal, 1: list<array<string, mixed>>, 2: bool} */
    private function competency(EmployeePerformanceAgreement $agreement): array
    {
        $assessments = $agreement->competencyAssessments()->with('competency')->get();
        if ($assessments->isEmpty()) {
            return [null, [], $this->settings->componentWeights()['competency'] === 0];
        }

        $max = $this->competencyScaleMax();
        $weighted = Dec::zero();
        $weights = Dec::zero();
        $rows = [];
        $complete = true;
        foreach ($assessments as $assessment) {
            $rating = $assessment->manager_rating;
            if ($rating === null) {
                $complete = false;
            }
            $score = $rating === null ? null : Dec::pct(BigDecimal::of($rating), BigDecimal::of($max));
            if ($score !== null) {
                $weighted = $weighted->plus($score->multipliedBy(Dec::of($assessment->weight)));
                $weights = $weights->plus(Dec::of($assessment->weight));
            }
            $rows[] = [
                'competency_id' => $assessment->competency_id,
                'code' => $assessment->competency?->code,
                'name_en' => $assessment->competency?->name_en,
                'name_am' => $assessment->competency?->name_am,
                'rating' => $rating,
                'scale_max' => $max,
                'score' => Dec::str($score),
                'weight' => Dec::str(Dec::of($assessment->weight)),
            ];
        }

        return [$weights->isZero() ? null : Dec::div($weighted, $weights), $rows, $complete];
    }

    private function competencyScaleMax(): int
    {
        $scale = PerformanceRatingScale::query()->where('scale_type', 'COMPETENCY')->where('is_active', true)->orderByDesc('is_default')->first();

        return max(1, (int) ($scale?->bands()->max('level_value') ?? 5));
    }

    /** @return array{scale_id: ?string, band_id: ?string, label_en: ?string, label_am: ?string} */
    private function band(BigDecimal $score): array
    {
        $scale = PerformanceRatingScale::query()->where('scale_type', 'RESULT')->where('is_active', true)->orderByDesc('is_default')->first();
        $rounded = BigDecimal::of(Dec::str($score));

        /** @var PerformanceRatingBand|null $band */
        $band = $scale?->bands()->get()->first(function (PerformanceRatingBand $band) use ($rounded): bool {
            $min = Dec::of($band->min_score);
            $max = Dec::of($band->max_score);

            return ($min === null || $rounded->isGreaterThanOrEqualTo($min)) && ($max === null || $rounded->isLessThanOrEqualTo($max));
        });

        return ['scale_id' => $scale?->getKey(), 'band_id' => $band?->getKey(), 'label_en' => $band?->label_en, 'label_am' => $band?->label_am];
    }

    private function ratio(mixed $numerator, mixed $denominator, bool $percentage): ?string
    {
        $n = Dec::of($numerator);
        $d = Dec::of($denominator);
        if ($n === null || $d === null) {
            return null;
        }

        return Dec::str($percentage ? Dec::pct($n, $d) : Dec::div($n, $d));
    }

    /** Public for re-rating a calibrated/appealed score with the same scale. */
    public function ratingFor(string $score): array
    {
        return $this->band(BigDecimal::of($score));
    }
}
