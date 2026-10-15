<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Enums\DailyActivityStatus;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Daily Operations: the Daily Activity Register as volumes — days reported,
 * how many people reported, what is waiting for review. Reporting is a
 * measure of the register being used, not of anyone's diligence; the
 * reporting rate compares people who reported at least one day with the
 * current headcount of the same scope.
 */
final class DailyOperationsAnalytics extends SituationAnalytics
{
    public const SOURCE = 'situationRoom.sources.daily_activity';

    /** Submitted and not yet decided by a reviewer. */
    public const AWAITING_REVIEW = ['submitted', 'under_review', 'resubmitted'];

    public function panels(SituationContext $context): array
    {
        return [
            'daily_kpis' => fn (): SituationPanel => $this->kpis($context),
            'daily_trend' => fn (): SituationPanel => $this->trendPanel($context),
            'daily_by_organization' => fn (): SituationPanel => $this->byOrganization($context),
            'daily_status' => fn (): SituationPanel => $this->status($context),
            'daily_categories' => fn (): SituationPanel => $this->categories($context),
            'daily_progress' => fn (): SituationPanel => $this->progress($context),
        ];
    }

    public static function base(SituationContext $context): Builder
    {
        $query = DB::table('daily_activity_logs as l');
        $context->scope->apply($query, 'l.organization_id');

        if (($unit = $context->filters->get('organization_unit_id')) !== null) {
            $query->where('l.organization_unit_id', $unit);
        }

        return $query;
    }

    /** Reported days in the period: anything past draft. */
    private function reported(SituationContext $context): Builder
    {
        return $this->inPeriod(self::base($context), 'l.activity_date', $context->period(), true)
            ->where('l.status', '!=', DailyActivityStatus::Draft->value);
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        $reported = $this->reported($context);
        $days = (clone $reported)->count();
        $people = (clone $reported)->distinct()->count('l.employee_id');
        $headcount = WorkforceQueries::headcount($context);
        $minutes = (clone $reported)->join('daily_activity_items as i', 'i.daily_activity_log_id', '=', 'l.id')->sum('i.duration_minutes');

        return SituationPanel::kpis('daily_kpis', $this->title('daily_kpis'), self::SOURCE, [
            $this->kpi('days_reported', $days),
            $this->kpi('people_reporting', $people),
            $this->kpi('reporting_rate', $this->percent($people, $headcount), ['unit' => '%']),
            $this->kpi('late_days', (clone $reported)->where('l.is_late', true)->count()),
            $this->kpi('awaiting_review', (clone self::base($context))->whereIn('l.status', self::AWAITING_REVIEW)->count()),
            $this->kpi('returned_for_correction', (clone self::base($context))->where('l.status', DailyActivityStatus::ReturnedForCorrection->value)->count()),
            $this->kpi('hours_recorded', round(((int) $minutes) / 60, 1), ['unit' => 'h']),
        ]);
    }

    private function trendPanel(SituationContext $context): SituationPanel
    {
        $trend = $this->trend(self::base($context)->where('l.status', '!=', DailyActivityStatus::Draft->value), 'l.activity_date', $context->period(), true);

        return $this->chart('daily_trend', 'line', self::SOURCE, $trend['categories'], [
            ['key' => 'days_reported', 'label' => SituationLabel::key('situationRoom.kpi.days_reported'), 'values' => $trend['values']],
        ], span: 2);
    }

    private function byOrganization(SituationContext $context): SituationPanel
    {
        $people = (clone $this->reported($context))
            ->selectRaw('l.organization_id AS organization_id, COUNT(DISTINCT l.employee_id) AS total')
            ->groupBy('l.organization_id')
            ->pluck('total', 'organization_id')
            ->map(fn ($v): int => (int) $v)
            ->all();
        $reporting = $this->rolledUp($context, $people);
        $headcount = $this->rolledUp($context, WorkforceQueries::headcountByOrganization($context));

        return $this->chart('daily_by_organization', 'hbar', self::SOURCE, $headcount['categories'], [
            ['key' => 'people_reporting', 'label' => SituationLabel::key('situationRoom.kpi.people_reporting'), 'values' => $this->align($reporting, $headcount)],
            ['key' => 'headcount', 'label' => SituationLabel::key('situationRoom.kpi.headcount'), 'values' => $headcount['values']],
        ], span: 2);
    }

    private function status(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->inPeriod(self::base($context), 'l.activity_date', $context->period(), true), 'l.status');
        $buckets = DailyActivityStatus::values();

        return $this->chart('daily_status', 'donut', self::SOURCE, $this->valueLabels('daily_status', $buckets), [
            ['key' => 'days', 'label' => SituationLabel::key('situationRoom.kpi.days_reported'), 'values' => array_map(fn (string $b): int => $counts[$b] ?? 0, $buckets)],
        ]);
    }

    private function categories(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy((clone $this->reported($context))->join('daily_activity_items as i', 'i.daily_activity_log_id', '=', 'l.id'), "COALESCE(i.activity_category, 'unknown')");
        $top = $this->topWithOther($counts);

        return $this->chart('daily_categories', 'hbar', self::SOURCE, $this->valueLabels('activity_category', array_map(fn ($k): string => $k === '__other' ? 'other' : (string) $k, array_keys($top))), [
            ['key' => 'items', 'label' => SituationLabel::key('situationRoom.series.activities'), 'values' => array_values($top)],
        ]);
    }

    private function progress(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy((clone $this->reported($context))->join('daily_activity_items as i', 'i.daily_activity_log_id', '=', 'l.id'), "COALESCE(i.progress_status, 'unknown')");
        ksort($counts);

        return $this->chart('daily_progress', 'donut', self::SOURCE, $this->valueLabels('progress_status', array_keys($counts)), [
            ['key' => 'items', 'label' => SituationLabel::key('situationRoom.series.activities'), 'values' => array_values($counts)],
        ]);
    }

    /**
     * Values of one roll-up in the row order of another (both come from the
     * same roll-up, but an "unplaced" row may exist in only one).
     *
     * @param  array{ids: list<string>, values: list<int|float>}  $values
     * @param  array{ids: list<string>}  $order
     * @return list<int|float>
     */
    private function align(array $values, array $order): array
    {
        $byId = array_combine($values['ids'], $values['values']) ?: [];

        return array_map(fn (string $id): int|float => $byId[$id] ?? 0, $order['ids']);
    }
}
