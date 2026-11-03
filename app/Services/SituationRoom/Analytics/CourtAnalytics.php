<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Administrative Tribunal Court, seen from the institutions it rules on:
 * cases filed against institutions in scope, how far each has come, the
 * hearings and decisions of the period, and the court's letters waiting to
 * be opened. Counts only; no applicant, respondent or judge is ever named.
 *
 * The institution-side register (institution_court_cases) carries the
 * organization, so it is the base of every query; the court's own case row
 * is joined for its status, hearings and decisions.
 */
final class CourtAnalytics extends SituationAnalytics
{
    public const SOURCE = 'situationRoom.sources.court';

    public const OPEN = ['pending', 'active', 'adjourned'];

    public const STATUSES = ['pending', 'active', 'adjourned', 'dismissed', 'closed'];

    public function panels(SituationContext $context): array
    {
        return [
            'court_kpis' => fn (): SituationPanel => $this->kpis($context),
            'court_status' => fn (): SituationPanel => $this->status($context),
            'court_filings_trend' => fn (): SituationPanel => $this->filingsTrend($context),
            'court_by_organization' => fn (): SituationPanel => $this->byOrganization($context),
            'court_case_types' => fn (): SituationPanel => $this->caseTypes($context),
        ];
    }

    /** The institution-side case register, in scope. */
    private function register(SituationContext $context): Builder
    {
        $query = DB::table('institution_court_cases as icc');
        $context->scope->apply($query, 'icc.organization_id');

        return $query;
    }

    /** The register joined to the court's case row, merged duplicates left out. */
    private function cases(SituationContext $context): Builder
    {
        return $this->register($context)
            ->join('court_cases as cc', 'cc.id', '=', 'icc.court_case_id')
            ->whereNull('cc.merged_into_case_id');
    }

    private function letters(SituationContext $context): Builder
    {
        $query = DB::table('court_ms_letters as l');
        $context->scope->apply($query, 'l.organization_id');

        return $query;
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        $period = $context->period();
        $hearings = $this->register($context)->join('court_case_hearings as h', 'h.case_id', '=', 'icc.court_case_id');
        $decisions = $this->register($context)->join('court_decisions as d', 'd.court_case_id', '=', 'icc.court_case_id');

        return SituationPanel::kpis('court_kpis', $this->title('court_kpis'), self::SOURCE, [
            $this->kpi('court_filed', $this->inPeriod($this->register($context), 'icc.filed_at', $period, true)->count()),
            $this->kpi('court_open', $this->cases($context)->whereIn('cc.status', self::OPEN)->count()),
            $this->kpi('court_awaiting_review', $this->register($context)->where('icc.review_status', 'awaiting_review')->count()),
            $this->kpi('court_hearings', $this->inPeriod($hearings, 'h.hearing_at', $period)->count()),
            $this->kpi('court_decisions', $this->inPeriod($decisions, 'd.decision_date', $period, true)->count()),
            $this->kpi('court_appealed', $this->inPeriod($this->cases($context), 'cc.appealed_at', $period)->count()),
            $this->kpi('court_letters', $this->inPeriod($this->letters($context), 'l.received_at', $period)->count()),
            $this->kpi('court_letters_unopened', $this->letters($context)->whereNull('l.opened_at')->count()),
        ]);
    }

    private function status(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->cases($context), 'cc.status');

        return $this->chart('court_status', 'donut', self::SOURCE, $this->valueLabels('court_status', self::STATUSES), [
            ['key' => 'cases', 'label' => SituationLabel::key('situationRoom.series.cases'), 'values' => array_map(fn (string $s): int => $counts[$s] ?? 0, self::STATUSES)],
        ]);
    }

    private function filingsTrend(SituationContext $context): SituationPanel
    {
        $trend = $this->trend($this->register($context), 'icc.filed_at', $context->period(), true);

        return $this->chart('court_filings_trend', 'line', self::SOURCE, $trend['categories'], [
            ['key' => 'court_filed', 'label' => SituationLabel::key('situationRoom.kpi.court_filed'), 'values' => $trend['values']],
        ], span: 2);
    }

    /** Open cases per roll-up row, small counts suppressed: a tribunal case can point to a person. */
    private function byOrganization(SituationContext $context): SituationPanel
    {
        $open = $this->rolledUp($context, $this->countBy($this->cases($context)->whereIn('cc.status', self::OPEN), 'icc.organization_id'));

        return $this->chart('court_by_organization', 'hbar', self::SOURCE, $open['categories'], [
            ['key' => 'court_open', 'label' => SituationLabel::key('situationRoom.kpi.court_open'), 'values' => $this->suppressSeries($open['values'])],
        ], span: 2);
    }

    /** What the cases are about, by the court's own case type names, the largest first. */
    private function caseTypes(SituationContext $context): SituationPanel
    {
        $counts = $this->topWithOther(array_filter($this->countBy($this->register($context), 'icc.case_type'), fn ($k): bool => (string) $k !== '', ARRAY_FILTER_USE_KEY));
        $categories = array_map(fn ($key): SituationLabel|string|array => $key === '__other' ? SituationLabel::key('situationRoom.values.common.other') : (string) $key, array_keys($counts));

        return $this->chart('court_case_types', 'bar', self::SOURCE, $categories, [
            ['key' => 'cases', 'label' => SituationLabel::key('situationRoom.series.cases'), 'values' => $this->suppressSeries(array_values($counts))],
        ]);
    }
}
