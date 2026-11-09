<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Institutional assessment: the cycles under way, how many of the eligible
 * staff each institution has assessed, where its submission stands, and the
 * results by band. Counts and shares only; no employee's score is shown and
 * small groups are protected as the module itself protects them.
 */
final class AssessmentAnalytics extends SituationAnalytics
{
    public const SOURCE = 'situationRoom.sources.assessment';

    public const SUBMISSION_STATUSES = ['submitted', 'under_city_review', 'returned', 'verified', 'finalized', 'rejected', 'outdated'];

    public const RECORD_STATUSES = ['assigned', 'submitted', 'reviewed', 'acknowledged', 'unassessed'];

    public function panels(SituationContext $context): array
    {
        return [
            'assessment_kpis' => fn (): SituationPanel => $this->kpis($context),
            'submission_status' => fn (): SituationPanel => $this->submissionStatus($context),
            'assessment_record_status' => fn (): SituationPanel => $this->recordStatus($context),
            'assessment_coverage_by_organization' => fn (): SituationPanel => $this->coverageByOrganization($context),
            'assessment_results_by_band' => fn (): SituationPanel => $this->resultsByBand($context),
        ];
    }

    /** Cycles whose assessment period covers today; cycles are city-wide, so they are not scoped. */
    private function activeCycles(): Builder
    {
        $today = now()->toDateString();

        return DB::table('assessment_cycles as cy')->where('cy.period_start', '<=', $today)->where('cy.period_end', '>=', $today);
    }

    private function submissions(SituationContext $context): Builder
    {
        $query = DB::table('assessment_institution_submissions as s');
        $context->scope->apply($query, 's.organization_id');

        return $query;
    }

    /** The submission that currently stands for each institution: the ones not superseded. */
    private function currentSubmissions(SituationContext $context): Builder
    {
        return $this->submissions($context)->whereNull('s.outdated_at');
    }

    private function records(SituationContext $context): Builder
    {
        $query = DB::table('assessment_records as r');
        $context->scope->apply($query, 'r.organization_id');

        return $query;
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        $period = $context->period();
        $inCycle = DB::table('assessment_cycle_organizations as aco')->whereIn('aco.assessment_cycle_id', $this->activeCycles()->select('cy.id'))->whereNull('aco.excluded_at');
        $context->scope->apply($inCycle, 'aco.organization_id');
        $eligible = (int) $this->currentSubmissions($context)->sum('s.eligible_count');
        $assessed = (int) $this->currentSubmissions($context)->sum('s.assessed_count');
        $late = $this->records($context)->join('assessment_responses as rs', 'rs.assessment_record_id', '=', 'r.id')->where('rs.submitted_late', true);

        return SituationPanel::kpis('assessment_kpis', $this->title('assessment_kpis'), self::SOURCE, [
            $this->kpi('cycles_active', $this->activeCycles()->count()),
            $this->kpi('institutions_in_cycle', $inCycle->count()),
            $this->kpi('submissions_received', $this->inPeriod($this->submissions($context), 's.submitted_at', $period)->count()),
            $this->kpi('submissions_finalized', $this->inPeriod($this->submissions($context), 's.finalized_at', $period)->count()),
            $this->kpi('assessment_coverage', $this->percent($assessed, $eligible), ['unit' => '%']),
            $this->kpi('records_finalized', $this->inPeriod($this->records($context), 'r.finalized_at', $period)->count()),
            $this->kpi('responses_late', $this->inPeriod($late, 'rs.submitted_at', $period)->count()),
        ]);
    }

    private function submissionStatus(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->currentSubmissions($context), 's.status');

        return $this->chart('submission_status', 'donut', self::SOURCE, $this->valueLabels('submission_status', self::SUBMISSION_STATUSES), [
            ['key' => 'submissions', 'label' => SituationLabel::key('situationRoom.series.submissions'), 'values' => array_map(fn (string $b): int => $counts[$b] ?? 0, self::SUBMISSION_STATUSES)],
        ]);
    }

    private function recordStatus(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->records($context)->whereIn('r.assessment_cycle_id', $this->activeCycles()->select('cy.id')), 'r.status');

        return $this->chart('assessment_record_status', 'bar', self::SOURCE, $this->valueLabels('assessment_record_status', self::RECORD_STATUSES), [
            ['key' => 'records', 'label' => SituationLabel::key('situationRoom.series.records'), 'values' => $this->suppressSeries(array_map(fn (string $b): int => $counts[$b] ?? 0, self::RECORD_STATUSES))],
        ]);
    }

    /** Eligible and assessed staff per roll-up row, from each institution's standing submission. */
    private function coverageByOrganization(SituationContext $context): SituationPanel
    {
        $eligible = $this->rolledUp($context, $this->sumBy($this->currentSubmissions($context), 's.organization_id', 's.eligible_count'));
        $assessed = $this->rolledUp($context, $this->sumBy($this->currentSubmissions($context), 's.organization_id', 's.assessed_count'));
        $byId = array_combine($assessed['ids'], $assessed['values']) ?: [];

        return $this->chart('assessment_coverage_by_organization', 'hbar', self::SOURCE, $eligible['categories'], [
            ['key' => 'eligible', 'label' => SituationLabel::key('situationRoom.series.eligible'), 'values' => array_map(fn ($v): int => (int) $v, $eligible['values'])],
            ['key' => 'assessed', 'label' => SituationLabel::key('situationRoom.series.assessed'), 'values' => array_map(fn (string $id): int => (int) ($byId[$id] ?? 0), $eligible['ids'])],
        ], span: 2);
    }

    /** Finalised records of the period by result band, with the band's own bilingual label. */
    private function resultsByBand(SituationContext $context): SituationPanel
    {
        $rows = $this->inPeriod($this->records($context), 'r.finalized_at', $context->period())
            ->whereNotNull('r.band_code')
            ->selectRaw('r.band_code AS bucket, MAX(r.band_label_en) AS label_en, MAX(r.band_label_am) AS label_am, COUNT(*) AS total')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return $this->chart('assessment_results_by_band', 'bar', self::SOURCE, $rows->map(fn ($row): array => ['en' => $row->label_en ?? (string) $row->bucket, 'am' => $row->label_am ?? null])->all(), [
            ['key' => 'records', 'label' => SituationLabel::key('situationRoom.series.records'), 'values' => $this->suppressSeries($rows->map(fn ($row): int => (int) $row->total)->all())],
        ]);
    }

    /**
     * A grouped sum, keyed by the group column.
     *
     * @return array<string, float>
     */
    private function sumBy(Builder $query, string $column, string $sum): array
    {
        return (clone $query)
            ->selectRaw("{$column} AS bucket, SUM({$sum}) AS total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->mapWithKeys(fn ($total, $bucket): array => [(string) $bucket => (float) $total])
            ->all();
    }
}
