<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Enums\Performance\AgreementStatus;
use App\Enums\Performance\KpiAggregation;
use App\Enums\Performance\KpiDataSource;
use App\Enums\Performance\KpiHealth;
use App\Enums\Performance\KpiMeasurementType;
use App\Models\EmployeePerformanceItem;
use App\Models\KpiActual;
use App\Models\KpiContribution;
use App\Models\KpiTarget;
use App\Models\PerformancePlan;
use App\Models\PerformancePlanScore;
use App\Services\Performance\Calculation\AggregateValue;
use App\Services\Performance\Calculation\Dec;
use App\Services\Performance\Calculation\KpiAchievementCalculator;
use App\Services\Performance\Calculation\KpiAggregationService;
use App\Services\Performance\Calculation\Observation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Unit, parent-unit and organization performance (docs/epms-calculation-rules.md §6).
 *
 * A plan's score is the weighted achievement of ITS OWN KPI targets:
 *
 *   objective score  Σ(target achievement × target weight) ÷ 100
 *   plan score       Σ(objective score × objective weight) ÷ 100
 *
 * Employee scores are never summed or averaged into a unit. A target's
 * actual is either
 *   (a) its own measurement (manual / system), which then WINS — children are
 *       not added on top of it; or
 *   (b) the combination, by the KPI's aggregation method, of its DIRECT
 *       contributors only: child targets (parent_target_id) and employee items
 *       adapting it (position_target_id).
 *
 * Each consumed row is recorded once in kpi_contributions (unique per parent
 * and source row) and each row reaches exactly one parent, so an output is
 * counted once at every level — employee → unit → parent → organization —
 * never four times. A transferred employee's old agreement keeps rolling up
 * into the old unit only.
 */
final class PerformanceAggregationService
{
    private const COUNTED_AGREEMENTS = [AgreementStatus::Active, AgreementStatus::UnderReview, AgreementStatus::Finalized, AgreementStatus::Closed];

    public function __construct(
        private readonly KpiAchievementCalculator $achievement,
        private readonly KpiAggregationService $aggregation,
        private readonly EpmsSettings $settings,
    ) {}

    /**
     * @param  list<string>  $visited  cycle guard
     * @return array{0: AggregateValue, 1: Collection<int, KpiActual>, 2: array<string, mixed>}
     */
    public function measureTarget(KpiTarget $target, Carbon $asOf, array $visited = []): array
    {
        $kpi = $target->kpi;
        $percentage = $kpi->measurement_type === KpiMeasurementType::Percentage;
        $end = Carbon::parse($target->period_end)->min($asOf);
        $start = Carbon::parse($target->period_start);

        if (in_array($target->getKey(), $visited, true)) {
            return [new AggregateValue(null, $kpi->aggregation_method->value, 'circular lineage ignored'), collect(), ['source' => 'none']];
        }
        $visited[] = $target->getKey();

        $own = KpiActual::query()->where('target_id', $target->getKey())->where('source_key', '!=', 'aggregate')
            ->where('period_start', '>=', $start->toDateString())->where('period_end', '<=', $end->toDateString())
            ->orderBy('period_end')->get();

        if ($own->isNotEmpty()) {
            $aggregate = $this->aggregation->aggregate($kpi->aggregation_method, $this->observations($own), $percentage);

            return [$aggregate, $own, ['source' => 'own', 'rows' => $own->count()]];
        }

        if (! $this->aggregation->canCombineChildren($kpi->aggregation_method)) {
            return [new AggregateValue(null, $kpi->aggregation_method->value, 'needs its own verified actual'), collect(), ['source' => 'none']];
        }

        $childAggregates = [];
        $consumed = collect();
        $contributors = [];

        foreach ($target->childTargets()->with('kpi')->get() as $child) {
            [$childAggregate, $rows] = $this->measureTarget($child, $asOf, $visited);
            if ($childAggregate->value === null && $childAggregate->numerator === null) {
                continue;
            }
            $childAggregates[] = $childAggregate;
            $consumed = $consumed->merge($rows->map(fn ($row) => ['row' => $row, 'type' => 'UNIT', 'id' => $child->performance_plan_id]));
            $contributors[] = ['type' => 'UNIT', 'target_id' => $child->getKey(), 'value' => $childAggregate->value];
        }

        $items = EmployeePerformanceItem::query()->where('position_target_id', $target->getKey())->where('is_current', true)
            ->whereHas('agreement', fn ($q) => $q->whereIn('status', array_map(fn ($s) => $s->value, self::COUNTED_AGREEMENTS)))
            ->with('agreement')->get();
        foreach ($items as $item) {
            $rows = KpiActual::query()->where('employee_performance_item_id', $item->getKey())
                ->where('source_type', $item->data_source_type->value)
                ->where('period_end', '<=', $end->toDateString())->orderBy('period_end')->get();
            if ($rows->isEmpty()) {
                continue;
            }
            $childAggregates[] = $this->aggregation->aggregate($kpi->aggregation_method, $this->observations($rows), $percentage);
            $consumed = $consumed->merge($rows->map(fn ($row) => ['row' => $row, 'type' => 'EMPLOYEE', 'id' => $item->agreement->employee_id]));
            $contributors[] = ['type' => 'EMPLOYEE', 'employee_id' => $item->agreement->employee_id, 'value' => end($childAggregates)->value];
        }

        if ($childAggregates === []) {
            return [new AggregateValue(null, $kpi->aggregation_method->value, 'nothing reported yet'), collect(), ['source' => 'none']];
        }

        $combined = $this->aggregation->combine($kpi->aggregation_method, $childAggregates, $percentage);
        $row = $this->persistAggregate($target, $start, $end, $combined, $consumed);

        return [$combined, collect([$row]), ['source' => 'contributors', 'contributors' => $contributors]];
    }

    /**
     * @param  Collection<int, array{row: KpiActual, type: string, id: string}>  $consumed
     */
    private function persistAggregate(KpiTarget $target, Carbon $start, Carbon $end, AggregateValue $combined, Collection $consumed): KpiActual
    {
        $plan = $target->plan;
        $row = KpiActual::query()->updateOrCreate(
            ['subject_key' => 'target:'.$target->getKey(), 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(), 'source_key' => 'aggregate'],
            [
                'kpi_id' => $target->kpi_id,
                'target_id' => $target->getKey(),
                'performance_plan_id' => $plan->getKey(),
                'organization_id' => $plan->organization_id,
                'organization_unit_id' => $plan->organization_unit_id,
                'actual_value' => $combined->value,
                'actual_numerator' => $combined->numerator,
                'actual_denominator' => $combined->denominator,
                'source_type' => KpiDataSource::Formula,
                'source_reference_type' => 'kpi_contributions',
                'verified' => true,
            ],
        );

        $keep = [];
        foreach ($consumed as $entry) {
            /** @var KpiActual $source */
            $source = $entry['row'];
            $contribution = KpiContribution::query()->updateOrCreate(
                ['parent_target_id' => $target->getKey(), 'source_actual_id' => $source->getKey()],
                [
                    'kpi_id' => $target->kpi_id,
                    'contributor_type' => $entry['type'],
                    'contributor_id' => (string) $entry['id'],
                    'contribution_value' => $source->actual_value,
                    'numerator' => $source->actual_numerator,
                    'denominator' => $source->actual_denominator,
                    'source_type' => $source->source_type->value,
                    'source_reference' => $source->subject_key,
                    'period_start' => $source->period_start,
                    'period_end' => $source->period_end,
                ],
            );
            $keep[] = $contribution->getKey();
        }
        KpiContribution::query()->where('parent_target_id', $target->getKey())->whereNotIn('id', $keep)->delete();

        return $row;
    }

    /** @return array<string, mixed> full trace of a plan score */
    public function planScore(PerformancePlan $plan, ?Carbon $asOf = null, bool $persist = true): array
    {
        $asOf ??= now();
        $objectives = [];
        $planScore = Dec::zero();
        $reported = false;

        foreach ($plan->objectives()->where('status', 'ACTIVE')->with('targets.kpi')->get() as $objective) {
            $targets = [];
            $objectiveScore = Dec::zero();
            foreach ($objective->targets as $target) {
                [$aggregate, , $lineage] = $this->measureTarget($target, $asOf);
                $kpi = $target->kpi;
                $percentage = $kpi->measurement_type === KpiMeasurementType::Percentage;
                $targetValue = $target->target_value ?? $this->ratio($target->target_numerator, $target->target_denominator, $percentage);
                $achievement = $this->achievement->calculate($kpi->direction, $targetValue, $aggregate->value, [
                    'cap' => (string) ($target->achievement_cap ?? $kpi->achievement_cap ?? $this->settings->defaultAchievementCap()),
                    'allow_overachievement' => (bool) $kpi->allow_overachievement,
                    'tolerance' => $target->tolerance ?? $kpi->target_tolerance,
                    'zero_score_deviation' => $target->zero_score_deviation ?? $kpi->zero_score_deviation,
                    'milestones' => $kpi->milestones,
                    'milestone_key' => $aggregate->milestoneKey,
                ]);
                $reported = $reported || $achievement->achievement !== null;
                $weighted = BigDecimal::of($achievement->scoringValue())->multipliedBy(Dec::of($target->weight))->dividedBy(100, 10, RoundingMode::HALF_UP);
                $objectiveScore = $objectiveScore->plus($weighted);
                $targets[] = [
                    'target_id' => $target->getKey(),
                    'kpi_code' => $kpi->code,
                    'kpi_name_en' => $kpi->name_en,
                    'kpi_name_am' => $kpi->name_am,
                    'aggregation' => $aggregate->toArray(),
                    'lineage' => $lineage,
                    'target' => $targetValue,
                    'actual' => $aggregate->value,
                    'achievement' => $achievement->achievement,
                    'achievement_formula' => $achievement->formula,
                    'weight' => Dec::str(Dec::of($target->weight)),
                    'weighted' => Dec::str($weighted),
                    'health' => $this->health($achievement->achievement, $kpi->aggregation_method, $target, $asOf)->value,
                ];
            }
            $contribution = $objectiveScore->multipliedBy(Dec::of($objective->weight))->dividedBy(100, 10, RoundingMode::HALF_UP);
            $planScore = $planScore->plus($contribution);
            $objectives[] = [
                'objective_id' => $objective->getKey(),
                'code' => $objective->code,
                'title_en' => $objective->title_en,
                'title_am' => $objective->title_am,
                'weight' => Dec::str(Dec::of($objective->weight)),
                'score' => Dec::str($objectiveScore),
                'contribution' => Dec::str($contribution),
                'targets' => $targets,
            ];
        }

        $trace = [
            'plan_id' => $plan->getKey(),
            'as_of' => $asOf->toDateString(),
            'formula' => [
                'objective' => 'Σ(target achievement × target weight) ÷ 100',
                'plan' => 'Σ(objective score × objective weight) ÷ 100',
                'target_actual' => 'own measurement if present, otherwise direct contributors combined by the KPI aggregation method',
            ],
            'score' => $reported ? Dec::str($planScore) : null,
            'objectives' => $objectives,
        ];

        if ($persist) {
            PerformancePlanScore::query()->updateOrCreate(
                ['performance_plan_id' => $plan->getKey(), 'as_of' => $asOf->toDateString()],
                ['cycle_id' => $plan->cycle_id, 'organization_id' => $plan->organization_id, 'organization_unit_id' => $plan->organization_unit_id, 'score' => $trace['score'], 'trace_json' => $trace, 'calculated_at' => now()],
            );
        }

        return $trace;
    }

    /**
     * Progress status. Cumulative (SUM) KPIs are compared with the share of
     * the period elapsed, so a yearly target is not "off track" in March.
     */
    public function health(?string $achievement, KpiAggregation $method, KpiTarget $target, Carbon $asOf): KpiHealth
    {
        return $this->healthFor($achievement, $method, Carbon::parse($target->period_start), Carbon::parse($target->period_end), $asOf);
    }

    /**
     * On track / at risk / off track against the configured thresholds. A SUM
     * KPI is judged against the share of its period that has elapsed, so an
     * annual count is not "off track" in the first quarter.
     */
    public function healthFor(?string $achievement, KpiAggregation $method, Carbon $start, Carbon $end, Carbon $asOf): KpiHealth
    {
        if ($achievement === null) {
            return KpiHealth::NotReported;
        }

        $value = BigDecimal::of($achievement);
        if ($method === KpiAggregation::Sum) {
            $total = max(1, $start->diffInDays($end) + 1);
            $elapsed = max(1, min($total, $start->diffInDays($asOf->min($end)) + 1));
            $value = $value->multipliedBy($total)->dividedBy($elapsed, 4, RoundingMode::HALF_UP);
        }

        return match (true) {
            $value->isGreaterThanOrEqualTo($this->settings->atRiskThreshold()) => KpiHealth::OnTrack,
            $value->isGreaterThanOrEqualTo($this->settings->offTrackThreshold()) => KpiHealth::AtRisk,
            default => KpiHealth::OffTrack,
        };
    }

    /**
     * @param  Collection<int, KpiActual>  $rows
     * @return list<Observation>
     */
    private function observations(Collection $rows): array
    {
        return $rows->map(fn (KpiActual $a): Observation => new Observation(
            $a->actual_value, $a->actual_numerator, $a->actual_denominator, null, $a->period_end->toDateString(), $a->milestone_key,
        ))->values()->all();
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
}
