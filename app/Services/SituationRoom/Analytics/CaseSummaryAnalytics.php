<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Enums\Discipline\DisciplinaryCaseStatus;
use App\Enums\GrievanceStatus;
use App\Enums\Performance\AppealStatus;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Grievance, Discipline and Appeal — a summary of caseload, kept strictly
 * apart from public complaints. An employee grievance, a disciplinary case,
 * a performance appeal and a public complaint are four different things with
 * four different procedures; none is counted as another and nothing here is
 * added to anything in Community Voice.
 *
 * Counts only. No names, subjects, parties or decisions, ever. Per
 * organization, any count small enough to point to a person is shown as
 * "fewer than N".
 */
final class CaseSummaryAnalytics extends SituationAnalytics
{
    public const SOURCE_GRIEVANCE = 'situationRoom.sources.grievances';

    public const SOURCE_DISCIPLINE = 'situationRoom.sources.discipline';

    public const DISCIPLINE_OPEN = ['charged', 'under_investigation', 'report_submitted', 'decided'];

    public function panels(SituationContext $context): array
    {
        return [
            'cases_separation' => fn (): SituationPanel => $this->notice('cases_separation', 'situationRoom.sources.cases', 'SEPARATE_DOMAINS', 3),
            'cases_kpis' => fn (): SituationPanel => $this->kpis($context),
            'grievance_stages' => fn (): SituationPanel => $this->grievanceStages($context),
            'discipline_status' => fn (): SituationPanel => $this->disciplineStatus($context),
            'cases_by_organization' => fn (): SituationPanel => $this->byOrganization($context),
            'grievance_trend' => fn (): SituationPanel => $this->grievanceTrend($context),
        ];
    }

    private function grievances(SituationContext $context): Builder
    {
        $query = DB::table('grievances as g')->where('g.status', '!=', GrievanceStatus::Draft->value);
        $context->scope->apply($query, 'g.organization_id');

        return $query;
    }

    private function discipline(SituationContext $context): Builder
    {
        $query = DB::table('disciplinary_cases as d')->where('d.status', '!=', DisciplinaryCaseStatus::Draft->value);
        $context->scope->apply($query, 'd.organization_id');

        return $query;
    }

    /** @return list<string> */
    private function finalGrievanceStatuses(): array
    {
        return array_values(array_map(fn (GrievanceStatus $s): string => $s->value, array_filter(GrievanceStatus::cases(), fn (GrievanceStatus $s): bool => $s->isFinal())));
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        $final = $this->finalGrievanceStatuses();
        $appeals = DB::table('performance_appeals as a');
        $context->scope->apply($appeals, 'a.organization_id');
        $grievanceAppeals = DB::table('grievance_appeals as ga')->join('grievances as g', 'g.id', '=', 'ga.grievance_id');
        $context->scope->apply($grievanceAppeals, 'g.organization_id');
        $tribunal = DB::table('administrative_tribunal_cases as tc')->join('grievances as g', 'g.id', '=', 'tc.grievance_id');
        $context->scope->apply($tribunal, 'g.organization_id');

        return SituationPanel::kpis('cases_kpis', $this->title('cases_kpis'), 'situationRoom.sources.cases', [
            $this->kpi('grievances_filed', $this->inPeriod($this->grievances($context), 'g.submitted_at', $context->period())->count()),
            $this->kpi('grievances_open', $this->grievances($context)->whereNotIn('g.status', $final)->count()),
            $this->kpi('grievances_concluded', $this->inPeriod($this->grievances($context), 'g.closed_at', $context->period())->count()),
            $this->kpi('grievance_appeals_filed', $this->inPeriod($grievanceAppeals, 'ga.filed_at', $context->period())->count()),
            $this->kpi('tribunal_referrals', (clone $tribunal)->count()),
            $this->kpi('discipline_open', $this->discipline($context)->whereIn('d.status', self::DISCIPLINE_OPEN)->count()),
            $this->kpi('discipline_opened', $this->inPeriod($this->discipline($context), 'd.charged_at', $context->period())->count()),
            $this->kpi('performance_appeals_open', (clone $appeals)->whereIn('a.status', [AppealStatus::Submitted->value, AppealStatus::UnderReview->value])->count()),
        ]);
    }

    /** Grievances grouped into four handling stages, not their 25 detailed statuses. */
    private function grievanceStages(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->grievances($context), 'g.status');
        $stages = ['intake' => 0, 'in_progress' => 0, 'decided' => 0, 'concluded' => 0];

        foreach ($counts as $status => $total) {
            $case = GrievanceStatus::tryFrom($status);
            $stage = match (true) {
                $case === null => 'in_progress',
                $case->isFinal() => 'concluded',
                in_array($case, [GrievanceStatus::DecisionIssued, GrievanceStatus::Approved, GrievanceStatus::Rejected], true) => 'decided',
                $case->isInProgress() => 'in_progress',
                default => 'intake',
            };
            $stages[$stage] += $total;
        }

        return $this->chart('grievance_stages', 'donut', self::SOURCE_GRIEVANCE, $this->valueLabels('grievance_stage', array_keys($stages)), [
            ['key' => 'cases', 'label' => SituationLabel::key('situationRoom.series.cases'), 'values' => array_values($stages)],
        ]);
    }

    private function disciplineStatus(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->discipline($context), 'd.status');
        $buckets = array_values(array_filter(array_column(DisciplinaryCaseStatus::cases(), 'value'), fn (string $s): bool => $s !== DisciplinaryCaseStatus::Draft->value));

        return $this->chart('discipline_status', 'bar', self::SOURCE_DISCIPLINE, $this->valueLabels('discipline_status', $buckets), [
            ['key' => 'cases', 'label' => SituationLabel::key('situationRoom.series.cases'), 'values' => $this->suppressSeries(array_map(fn (string $b): int => $counts[$b] ?? 0, $buckets))],
        ]);
    }

    /** Open caseload per roll-up row, small counts suppressed. */
    private function byOrganization(SituationContext $context): SituationPanel
    {
        $grievances = $this->rolledUp($context, $this->countBy($this->grievances($context)->whereNotIn('g.status', $this->finalGrievanceStatuses()), 'g.organization_id'));
        $discipline = $this->rolledUp($context, $this->countBy($this->discipline($context)->whereIn('d.status', self::DISCIPLINE_OPEN), 'd.organization_id'));
        $byId = array_combine($discipline['ids'], $discipline['values']) ?: [];

        return $this->chart('cases_by_organization', 'hbar', 'situationRoom.sources.cases', $grievances['categories'], [
            ['key' => 'grievances_open', 'label' => SituationLabel::key('situationRoom.kpi.grievances_open'), 'values' => $this->suppressSeries($grievances['values'])],
            ['key' => 'discipline_open', 'label' => SituationLabel::key('situationRoom.kpi.discipline_open'), 'values' => $this->suppressSeries(array_map(fn (string $id): int => (int) ($byId[$id] ?? 0), $grievances['ids']))],
        ], span: 2);
    }

    private function grievanceTrend(SituationContext $context): SituationPanel
    {
        $trend = $this->trend($this->grievances($context), 'g.submitted_at', $context->period());

        return $this->chart('grievance_trend', 'line', self::SOURCE_GRIEVANCE, $trend['categories'], [
            ['key' => 'grievances_filed', 'label' => SituationLabel::key('situationRoom.kpi.grievances_filed'), 'values' => $trend['values']],
        ], span: 2);
    }
}
