<?php

declare(strict_types=1);

namespace App\Services\Intelligence\Business;

use App\Services\Intelligence\Analysis\Baseline;
use App\Services\Intelligence\IntelligenceAnalysis;
use App\Services\SituationRoom\Analytics\WorkforceQueries;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationMemo;
use App\Services\SituationRoom\SituationPanel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Performance\Contracts\Data\AgreementStatus;
use Modules\Performance\Contracts\Data\AppealStatus;
use Modules\Performance\Contracts\Data\CalibrationStatus;
use Modules\Performance\Contracts\Data\PlanStatus;
use Modules\Performance\Contracts\Data\ResultStatus;
use Modules\Performance\Contracts\Data\ReviewStatus;
use Modules\Performance\Contracts\Data\ReviewType;

/**
 * Performance Intelligence: how far the EPMS cycle has got, step by step,
 * using the workflow's own statuses (plans, agreements, reviews, results,
 * calibration, appeals) and the cycle's own dates for "overdue". Scores are
 * never summed or averaged across people; the only rating view is the
 * distribution of RELEASED results under the small-group rule, as the
 * Situation Room shows it. Nothing here ranks a person or an organization.
 */
final class PerformanceAnalysis extends IntelligenceAnalysis
{
    use BusinessQueries;

    public const AGREED = [AgreementStatus::Agreed, AgreementStatus::Active, AgreementStatus::UnderReview, AgreementStatus::Finalized, AgreementStatus::Closed];

    public const NOT_AGREED = [AgreementStatus::Draft, AgreementStatus::PendingEmployeeReview, AgreementStatus::PendingManagerApproval, AgreementStatus::Returned];

    public const COMPLETION_DROP_PERCENT = 10.0;

    public function panels(SituationContext $context): array
    {
        return [
            'epms_kpis' => fn (): SituationPanel => $this->kpis($context),
            'epms_cycles' => fn (): SituationPanel => $this->cycles($context),
            'epms_agreements' => fn (): SituationPanel => $this->statusChart($context, 'epms_agreements', 'employee_performance_agreements', 'agreement_status', AgreementStatus::values()),
            'epms_reviews' => fn (): SituationPanel => $this->reviews($context),
            'epms_results' => fn (): SituationPanel => $this->statusChart($context, 'epms_results', 'performance_results', 'result_status', ResultStatus::values(), currentOnly: true),
            'epms_ratings' => fn (): SituationPanel => $this->ratings($context),
            'epms_overdue' => fn (): SituationPanel => $this->overdue($context),
            'epms_reporting_gaps' => fn (): SituationPanel => $this->reportingGaps($context),
        ];
    }

    public function headline(SituationContext $context): SituationPanel
    {
        if ($this->currentCycles() === []) {
            return $this->notice('epms_headline', $this->source('epms'), 'NO_CURRENT_CYCLE', 3);
        }
        $p = $this->progress($context);
        $o = $this->overdueCounts($context);

        return SituationPanel::kpis('epms_headline', $this->title('epms_headline'), $this->source('epms'), [
            $this->kpi('agreement_coverage', $p['coverage'], ['unit' => '%']),
            $this->kpi('results_released', $p['results_released']),
            $this->kpi('overdue_steps', $o === null ? null : array_sum($o)),
            $this->kpi('appeals_pending', $p['appeals_pending']),
        ], $this->hint('epms_headline'));
    }

    public function insights(SituationContext $context, array $panels): array
    {
        $cycles = $this->currentCycles();
        if ($cycles === []) {
            return [];
        }
        $insights = [];
        $p = $this->progress($context);

        // Completion against the previous cycle: only once the current cycle's agreement phase has ended,
        // otherwise a cycle still being agreed would always look worse than a finished one.
        $previous = $this->previousCycleAgreed($context);
        if ($previous !== null && $this->agreementPhaseEnded() && $previous['agreed'] >= 10) {
            $drop = Baseline::changePercent($p['agreements_in_place'], $previous['agreed']);
            if ($drop !== null && $drop <= -self::COMPLETION_DROP_PERCENT) {
                $insights[] = $this->insight('epms_completion_below_previous', 'medium', [
                    'current' => $p['agreements_in_place'], 'baseline' => $previous['agreed'], 'percent' => abs($drop), 'name' => $previous['name'],
                ], [
                    $this->evidence('agreements_in_place', $p['agreements_in_place']),
                    $this->evidence('previous_cycle_agreements', $previous['agreed']),
                    ['label' => SituationLabel::names($previous['name'], $previous['name_am']), 'value' => $previous['agreed'], 'href' => null],
                ], $this->change($p['agreements_in_place'], $previous['agreed']), confidence: 'medium', href: $this->link('performance.cycles.index', 'cycles.index'), source: $this->source('epms'), metric: 'agreements_in_place');
            }
        }

        $o = $this->overdueCounts($context);
        if ($o !== null && array_sum($o) > 0) {
            $insights[] = $this->insight('epms_overdue_steps', $this->severityByCount(array_sum($o)), ['count' => array_sum($o)], [
                $this->evidence('agreements_overdue', $o['agreements_overdue']),
                $this->evidence('midyear_reviews_overdue', $o['midyear_reviews_overdue']),
                $this->evidence('yearend_reviews_overdue', $o['yearend_reviews_overdue']),
            ], href: $this->link('performance.cycles.index', 'cycles.index'), source: $this->source('epms'), metric: 'overdue_steps');
        }

        $gaps = $this->reportingGapCount($context);
        if ($gaps > 0) {
            $insights[] = $this->insight('epms_reporting_gaps', $this->severityByCount($gaps), ['count' => $gaps], [
                $this->evidence('targets_without_actuals', $gaps),
                $this->evidence('window', $context->period()->from->toDateString().' – '.$context->period()->to->toDateString()),
            ], source: $this->source('epms'), metric: 'reporting_gaps');
        }

        return $insights;
    }

    /** @return list<string> */
    private function currentCycles(): array
    {
        return app(SituationMemo::class)->remember('intelligence:epms:cycles', fn (): array => DB::table('performance_cycles')->where('is_current', true)->pluck('id')->map(fn ($id): string => (string) $id)->all());
    }

    /** @param  list<string>  $cycles */
    private function scoped(SituationContext $context, string $table, array $cycles): Builder
    {
        $query = DB::table("{$table} as t")->whereIn('t.cycle_id', $cycles);
        $context->scope->apply($query, 't.organization_id');

        return $query;
    }

    /** Reviews belong to an agreement; scope and cycle come from it. */
    private function reviewsQuery(SituationContext $context, array $cycles): Builder
    {
        $query = DB::table('performance_reviews as r')->join('employee_performance_agreements as a', 'a.id', '=', 'r.agreement_id')->whereIn('a.cycle_id', $cycles);
        $context->scope->apply($query, 'a.organization_id');

        return $query;
    }

    /** @return array{plans_published: int, agreements_in_place: int, coverage: ?float, reviews_completed: int, results_released: int, calibration_open: int, appeals_pending: int} */
    public function progress(SituationContext $context): array
    {
        return app(SituationMemo::class)->remember(SituationMemo::key($context, 'intelligence:epms:progress'), function () use ($context): array {
            $cycles = $this->currentCycles();
            $agreed = $this->scoped($context, 'employee_performance_agreements', $cycles)->whereIn('t.status', array_map(fn (AgreementStatus $s): string => $s->value, self::AGREED))->distinct()->count('t.employee_id');

            return [
                'plans_published' => $this->scoped($context, 'performance_plans', $cycles)->where('t.status', PlanStatus::Published->value)->count(),
                'agreements_in_place' => $agreed,
                // Without a current cycle there is nothing to cover: null, never a false zero.
                'coverage' => $cycles === [] ? null : $this->percent($agreed, WorkforceQueries::headcount($context)),
                'reviews_completed' => $this->reviewsQuery($context, $cycles)->where('r.status', ReviewStatus::Completed->value)->count(),
                'results_released' => $this->scoped($context, 'performance_results', $cycles)->where('t.is_current', true)->where('t.status', ResultStatus::Released->value)->count(),
                'calibration_open' => $this->scoped($context, 'performance_calibration_sessions', $cycles)->whereIn('t.status', [CalibrationStatus::Draft->value, CalibrationStatus::InProgress->value])->count(),
                'appeals_pending' => $this->scoped($context, 'performance_appeals', $cycles)->whereIn('t.status', [AppealStatus::Submitted->value, AppealStatus::UnderReview->value])->count(),
            ];
        });
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        if ($this->currentCycles() === []) {
            return $this->notice('epms_kpis', $this->source('epms'), 'NO_CURRENT_CYCLE', 3);
        }
        $p = $this->progress($context);

        return SituationPanel::kpis('epms_kpis', $this->title('epms_kpis'), $this->source('epms'), [
            $this->kpi('plans_published', $p['plans_published']),
            $this->kpi('agreements_in_place', $p['agreements_in_place']),
            $this->kpi('agreement_coverage', $p['coverage'], ['unit' => '%']),
            $this->kpi('reviews_completed', $p['reviews_completed']),
            $this->kpi('results_released', $p['results_released']),
            $this->kpi('calibration_open', $p['calibration_open']),
            $this->kpi('appeals_pending', $p['appeals_pending']),
        ], $this->hint('epms_kpis'));
    }

    /** The current cycles and where their calendar stands: names of cycles, never of people. */
    private function cycles(SituationContext $context): SituationPanel
    {
        $rows = DB::table('performance_cycles')->where('is_current', true)->orderBy('start_date')
            ->get(['name_en', 'name_am', 'status', 'start_date', 'end_date', 'planning_end_date', 'midyear_review_end_date', 'yearend_review_end_date'])
            ->map(fn ($c): array => [
                'cycle' => SituationLabel::names((string) $c->name_en, $c->name_am),
                'status' => $this->value('cycle_status', (string) $c->status),
                'start_date' => (string) $c->start_date,
                'end_date' => (string) $c->end_date,
                'planning_end_date' => $c->planning_end_date === null ? null : (string) $c->planning_end_date,
                'midyear_review_end_date' => $c->midyear_review_end_date === null ? null : (string) $c->midyear_review_end_date,
                'yearend_review_end_date' => $c->yearend_review_end_date === null ? null : (string) $c->yearend_review_end_date,
            ])->all();

        return SituationPanel::table('epms_cycles', $this->title('epms_cycles'), $this->hint('epms_cycles'), $this->source('epms'), [
            $this->column('cycle', 'label'),
            $this->column('status', 'label'),
            $this->column('start_date', 'date'),
            $this->column('end_date', 'date'),
            $this->column('planning_end_date', 'date'),
            $this->column('midyear_review_end_date', 'date'),
            $this->column('yearend_review_end_date', 'date'),
        ], $rows, span: 3);
    }

    /** @param  list<string>  $buckets */
    private function statusChart(SituationContext $context, string $key, string $table, string $labels, array $buckets, bool $currentOnly = false): SituationPanel
    {
        $cycles = $this->currentCycles();
        if ($cycles === []) {
            return $this->notice($key, $this->source('epms'), 'NO_CURRENT_CYCLE');
        }
        $query = $this->scoped($context, $table, $cycles);
        if ($currentOnly) {
            $query->where('t.is_current', true);
        }
        $counts = $this->countBy($query, 't.status');

        return $this->chart($key, 'bar', $this->source('epms'), $this->valueLabels($labels, $buckets), [
            ['key' => 'count', 'label' => SituationLabel::key('situationRoom.series.count'), 'values' => array_map(fn (string $b): int => $counts[$b] ?? 0, $buckets)],
        ]);
    }

    /** Review type across, review status stacked. */
    private function reviews(SituationContext $context): SituationPanel
    {
        $cycles = $this->currentCycles();
        if ($cycles === []) {
            return $this->notice('epms_reviews', $this->source('epms'), 'NO_CURRENT_CYCLE');
        }
        // Aliases that are not column names of either joined table: SQLite calls `status` ambiguous in GROUP BY.
        $rows = $this->reviewsQuery($context, $cycles)->selectRaw('r.review_type AS type_bucket, r.status AS status_bucket, COUNT(*) AS total')->groupBy('type_bucket', 'status_bucket')->get();
        $matrix = [];
        foreach ($rows as $row) {
            $matrix[(string) $row->type_bucket][(string) $row->status_bucket] = (int) $row->total;
        }
        $types = ReviewType::values();
        $series = [];
        foreach (ReviewStatus::values() as $status) {
            $series[] = ['key' => $status, 'label' => $this->value('review_status', $status), 'values' => array_map(fn (string $t): int => $matrix[$t][$status] ?? 0, $types)];
        }

        return $this->chart('epms_reviews', 'stacked', $this->source('epms'), array_map(fn (string $t): array => $this->value('review_type', $t), $types), $series);
    }

    private function ratings(SituationContext $context): SituationPanel
    {
        $cycles = $this->currentCycles();
        if ($cycles === []) {
            return $this->notice('epms_ratings', $this->source('epms'), 'NO_CURRENT_CYCLE');
        }
        $rows = $this->scoped($context, 'performance_results', $cycles)
            ->where('t.is_current', true)->where('t.status', ResultStatus::Released->value)
            ->selectRaw("COALESCE(t.rating_label_en, '') AS label_en, COALESCE(t.rating_label_am, '') AS label_am, COUNT(*) AS total")
            ->groupBy('t.rating_label_en', 't.rating_label_am')
            ->orderBy('t.rating_label_en')
            ->get();

        return $this->chart('epms_ratings', 'bar', $this->source('epms'),
            $rows->map(fn ($r): array => SituationLabel::names($r->label_en ?: null, $r->label_am ?: null))->all(),
            [['key' => 'count', 'label' => SituationLabel::key('situationRoom.series.count'), 'values' => $this->suppressSeries($rows->map(fn ($r): int => (int) $r->total)->all())]],
            span: 2,
        );
    }

    /**
     * Overdue by the cycle's own calendar: agreements not yet agreed after
     * planning_end_date, reviews not completed after their review period's
     * end. Null when no current cycle records those dates: nothing is
     * assumed about a deadline nobody set.
     *
     * @return array{agreements_overdue: int, midyear_reviews_overdue: int, yearend_reviews_overdue: int}|null
     */
    public function overdueCounts(SituationContext $context): ?array
    {
        return app(SituationMemo::class)->remember(SituationMemo::key($context, 'intelligence:epms:overdue'), function () use ($context): ?array {
            $today = CarbonImmutable::today()->toDateString();
            $dated = DB::table('performance_cycles')->where('is_current', true)
                ->where(fn ($q) => $q->whereNotNull('planning_end_date')->orWhereNotNull('midyear_review_end_date')->orWhereNotNull('yearend_review_end_date'))
                ->exists();
            if (! $dated) {
                return null;
            }
            $pastPlanning = DB::table('performance_cycles')->where('is_current', true)->whereNotNull('planning_end_date')->where('planning_end_date', '<', $today)->pluck('id')->map(fn ($id): string => (string) $id)->all();
            $pastMidyear = DB::table('performance_cycles')->where('is_current', true)->whereNotNull('midyear_review_end_date')->where('midyear_review_end_date', '<', $today)->pluck('id')->map(fn ($id): string => (string) $id)->all();
            $pastYearend = DB::table('performance_cycles')->where('is_current', true)->whereNotNull('yearend_review_end_date')->where('yearend_review_end_date', '<', $today)->pluck('id')->map(fn ($id): string => (string) $id)->all();
            $reviewsOverdue = fn (array $cycles, ReviewType $type): int => $cycles === [] ? 0 : $this->reviewsQuery($context, $cycles)->where('r.review_type', $type->value)->where('r.status', '!=', ReviewStatus::Completed->value)->count();

            return [
                'agreements_overdue' => $pastPlanning === [] ? 0 : $this->scoped($context, 'employee_performance_agreements', $pastPlanning)->whereIn('t.status', array_map(fn (AgreementStatus $s): string => $s->value, self::NOT_AGREED))->count(),
                'midyear_reviews_overdue' => $reviewsOverdue($pastMidyear, ReviewType::MidYear),
                'yearend_reviews_overdue' => $reviewsOverdue($pastYearend, ReviewType::YearEnd),
            ];
        });
    }

    private function overdue(SituationContext $context): SituationPanel
    {
        if ($this->currentCycles() === []) {
            return $this->notice('epms_overdue', $this->source('epms'), 'NO_CURRENT_CYCLE');
        }
        $o = $this->overdueCounts($context);
        if ($o === null) {
            return $this->notice('epms_overdue', $this->source('epms'), 'NEEDS_DATA');
        }

        return SituationPanel::kpis('epms_overdue', $this->title('epms_overdue'), $this->source('epms'), [
            $this->kpi('agreements_overdue', $o['agreements_overdue']),
            $this->kpi('midyear_reviews_overdue', $o['midyear_reviews_overdue']),
            $this->kpi('yearend_reviews_overdue', $o['yearend_reviews_overdue']),
        ], $this->hint('epms_overdue'));
    }

    /**
     * Current KPI targets of published plans in the current cycles whose
     * period overlaps the view's, with no actual recorded for that overlap.
     */
    public function reportingGapCount(SituationContext $context): int
    {
        return app(SituationMemo::class)->remember(SituationMemo::key($context, 'intelligence:epms:gaps'), function () use ($context): int {
            $cycles = $this->currentCycles();
            if ($cycles === []) {
                return 0;
            }
            [$from, $to] = $this->bounds($context->period(), true);
            $query = DB::table('kpi_targets as kt')
                ->join('performance_plans as pp', 'pp.id', '=', 'kt.performance_plan_id')
                ->whereIn('pp.cycle_id', $cycles)->where('pp.status', PlanStatus::Published->value)
                ->where('kt.is_current', true)
                ->where('kt.period_start', '<=', $to)->where('kt.period_end', '>=', $from)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('kpi_actuals as ka')->whereColumn('ka.target_id', 'kt.id')
                    ->where('ka.period_start', '<=', $to)->where('ka.period_end', '>=', $from));
            $context->scope->apply($query, 'pp.organization_id');

            return (int) $query->count();
        });
    }

    private function reportingGaps(SituationContext $context): SituationPanel
    {
        if ($this->currentCycles() === []) {
            return $this->notice('epms_reporting_gaps', $this->source('epms'), 'NO_CURRENT_CYCLE');
        }

        return SituationPanel::kpis('epms_reporting_gaps', $this->title('epms_reporting_gaps'), $this->source('epms'), [
            $this->kpi('reporting_gaps', $this->reportingGapCount($context)),
        ], $this->hint('epms_reporting_gaps'));
    }

    private function agreementPhaseEnded(): bool
    {
        $today = CarbonImmutable::today()->toDateString();

        return DB::table('performance_cycles')->where('is_current', true)->whereNotNull('planning_end_date')->where('planning_end_date', '<', $today)->exists()
            && ! DB::table('performance_cycles')->where('is_current', true)->where(fn ($q) => $q->whereNull('planning_end_date')->orWhere('planning_end_date', '>=', $today))->exists();
    }

    /**
     * The most recent cycle that ended before the current ones started, and
     * how many employees in scope had an agreement in place in it.
     *
     * @return array{name: string, name_am: ?string, agreed: int}|null
     */
    private function previousCycleAgreed(SituationContext $context): ?array
    {
        $earliest = DB::table('performance_cycles')->where('is_current', true)->min('start_date');
        if ($earliest === null) {
            return null;
        }
        $previous = DB::table('performance_cycles')->where('is_current', false)->where('end_date', '<', (string) $earliest)->orderByDesc('end_date')->first(['id', 'name_en', 'name_am']);
        if ($previous === null) {
            return null;
        }
        $agreed = $this->scoped($context, 'employee_performance_agreements', [(string) $previous->id])
            ->whereIn('t.status', array_map(fn (AgreementStatus $s): string => $s->value, self::AGREED))->distinct()->count('t.employee_id');

        return ['name' => (string) $previous->name_en, 'name_am' => $previous->name_am, 'agreed' => (int) $agreed];
    }
}
