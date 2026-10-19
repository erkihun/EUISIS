<?php

declare(strict_types=1);

namespace App\Services\Intelligence\Business;

use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Enums\EstablishmentStatus;
use App\Enums\OrganizationDesignStatus;
use App\Enums\TransferStatus;
use App\Models\Occupation;
use App\Services\Intelligence\Analysis\Baseline;
use App\Services\Intelligence\IntelligenceAnalysis;
use App\Services\Intelligence\IntelligenceSettings;
use App\Services\SituationRoom\Analytics\WorkforceQueries;
use App\Services\SituationRoom\OrganizationRollup;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationMemo;
use App\Services\SituationRoom\SituationPanel;
use App\Services\SituationRoom\SituationSettings;
use App\Support\Database\SqlDateBucket;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Workforce Intelligence: the five staffing figures kept apart on purpose —
 * Required FTE and Approved Headcount come from APPROVED manpower plans
 * (what the organization should have), Approved Positions and Occupied
 * Positions from establishments and current assignments (what it is
 * entitled to and what it fills, rule D-2 of PositionCapacityService), and
 * Employee Count from current active assignments (who is actually employed).
 * None of them is derived from another, so a gap between them is a finding,
 * not a rounding error.
 *
 * Every query starts from the viewer's scope; people are counted, never
 * listed. Composition charts are top-N + other. Leadership vacancies need a
 * marker the schema does not hold (position_requirements.position_type is an
 * employment type), so they are reported as NEEDS_DATA, never inferred from
 * a title.
 */
final class WorkforceAnalysis extends IntelligenceAnalysis
{
    use BusinessQueries;

    public const TRANSFER_MINIMUM = 5;

    public function __construct(
        OrganizationRollup $rollup,
        SituationSettings $settings,
        private readonly IntelligenceSettings $intelligence,
    ) {
        parent::__construct($rollup, $settings);
    }

    public function panels(SituationContext $context): array
    {
        return [
            'workforce_figures' => fn (): SituationPanel => $this->figures($context),
            'workforce_by_organization' => fn (): SituationPanel => $this->byOrganization($context),
            'workforce_occupations' => fn (): SituationPanel => $this->occupations($context),
            'workforce_grades' => fn (): SituationPanel => $this->grades($context),
            'workforce_leadership_vacancies' => fn (): SituationPanel => $this->notice('workforce_leadership_vacancies', $this->source('workforce'), 'NEEDS_DATA'),
            'workforce_anomalies' => fn (): SituationPanel => $this->anomalies($context),
            'workforce_design_gaps' => fn (): SituationPanel => $this->designGaps($context),
            'workforce_transfers' => fn (): SituationPanel => $this->transfers($context),
            'workforce_transfers_by_organization' => fn (): SituationPanel => $this->transfersByOrganization($context),
            'workforce_assignment_starts' => fn (): SituationPanel => $this->assignmentStarts($context),
        ];
    }

    /** The compact strip the Overview composes. */
    public function headline(SituationContext $context): SituationPanel
    {
        $f = $this->fiveFigures($context);

        return SituationPanel::kpis('workforce_headline', $this->title('workforce_headline'), $this->source('workforce'), [
            $this->kpi('employee_count', $f['employee_count']),
            $this->kpi('capacity', $f['capacity']),
            $this->kpi('vacant_positions', $f['vacant']),
            $this->kpi('vacancy_rate', $f['vacancy_rate'], ['unit' => '%']),
        ], $this->hint('workforce_headline'));
    }

    public function insights(SituationContext $context, array $panels): array
    {
        $insights = [];
        $figures = $this->fiveFigures($context);
        $threshold = $this->settings->threshold('vacancy_rate_percent');

        // Vacancy rate: only against a limit somebody configured (D-2: no invented SLO).
        if ($threshold !== null && $figures['vacancy_rate'] !== null && $figures['vacancy_rate'] > $threshold) {
            $insights[] = $this->insight(
                'vacancy_rate_above_threshold',
                $figures['vacancy_rate'] >= 1.5 * $threshold ? 'high' : 'medium',
                ['percent' => $figures['vacancy_rate'], 'threshold' => $threshold, 'count' => $figures['vacant']],
                [
                    $this->evidence('vacant_positions', $figures['vacant']),
                    $this->evidence('capacity', $figures['capacity']),
                    $this->evidence('threshold', $threshold.' %'),
                ],
                $this->change($figures['vacancy_rate'], $threshold, '%'),
                source: $this->source('workforce'),
                metric: 'vacancy_rate',
            );
        }

        $anomalies = $this->anomalyCounts($context);
        $total = array_sum($anomalies);
        if ($total > 0) {
            $insights[] = $this->insight(
                'assignment_anomalies',
                $this->severityByCount($total),
                ['count' => $total],
                [
                    $this->evidence('multiple_current_assignments', $anomalies['multiple_current_assignments']),
                    $this->evidence('active_without_assignment', $anomalies['active_without_assignment']),
                    $this->evidence('positions_over_capacity', $anomalies['positions_over_capacity']),
                ],
                href: $this->link('employees.index'),
                source: $this->source('workforce'),
                metric: 'assignment_anomalies',
            );
        }

        $outflow = $this->outflowBaseline($context);
        if ($outflow !== null && Baseline::isAbnormallyHigh($outflow['current_rate'], $outflow['history'], $this->intelligence->anomalySigma(), self::TRANSFER_MINIMUM)) {
            $stats = Baseline::describe($outflow['history']);
            $insights[] = $this->insight(
                'transfer_outflow_abnormal',
                'medium',
                ['current' => $outflow['current'], 'baseline' => round((float) $stats['median'], 1), 'count' => $outflow['current']],
                [
                    $this->evidence('outbound_transfers', $outflow['current']),
                    $this->evidence('baseline_median_monthly', round((float) $stats['median'], 1)),
                    $this->evidence('baseline_window', count($outflow['history']).' months'),
                    $this->evidence('window', $context->period()->from->toDateString().' – '.$context->period()->to->toDateString()),
                ],
                $this->change($outflow['current_rate'], $stats['median']),
                href: $this->link('transfers.dashboard'),
                source: $this->source('transfers'),
                metric: 'transfers_outbound',
            );
        }

        return $insights;
    }

    /**
     * The five figures, memoised per request because the headline, the
     * figures panel and the insights all read them.
     *
     * @return array{required_fte: ?float, approved_headcount: ?int, approved_positions: int, approved_slots: int, capacity: int, occupied: int, vacant: int, employee_count: int, vacancy_rate: ?float, over: int}
     */
    public function fiveFigures(SituationContext $context): array
    {
        return app(SituationMemo::class)->remember(SituationMemo::key($context, 'intelligence:workforce:figures'), function () use ($context): array {
            $capacity = WorkforceQueries::capacityByOrganization($context);
            $sum = fn (string $key): int => array_sum(array_column($capacity, $key));
            $today = CarbonImmutable::today()->toDateString();

            // Approved positions and slots: only positions with an establishment approved and effective today.
            $establishment = DB::table('positions as p')
                ->join('position_establishments as pe', 'pe.position_id', '=', 'p.id')
                ->whereNull('p.deleted_at')->where('p.is_active', true)
                ->where('pe.status', EstablishmentStatus::Approved->value)->whereNull('pe.deleted_at')
                ->whereRaw('DATE(pe.effective_from) <= ?', [$today])
                ->where(fn ($q) => $q->whereNull('pe.effective_to')->orWhereRaw('DATE(pe.effective_to) >= ?', [$today]));
            $context->scope->apply($establishment, 'p.organization_id');
            if (($unit = $context->filters->get('organization_unit_id')) !== null) {
                $establishment->where('p.organization_unit_id', $unit);
            }
            $approved = $establishment->selectRaw('COUNT(DISTINCT p.id) AS positions, COALESCE(SUM(pe.approved_slots), 0) AS slots')->first();

            // Required FTE and approved headcount: requirements of APPROVED manpower plans only.
            $plans = DB::table('position_requirements as pr')
                ->join('manpower_plans as mp', 'mp.id', '=', 'pr.plan_id')
                ->where('mp.status', OrganizationDesignStatus::Approved->value)->whereNull('mp.deleted_at')
                ->whereIn('pr.status', ['approved', 'implemented']);
            $context->scope->apply($plans, 'pr.organization_id');
            if ($unit !== null) {
                $plans->where('pr.organization_unit_id', $unit);
            }
            $planned = $plans->selectRaw('COUNT(*) AS n, COALESCE(SUM(pr.required_fte), 0) AS fte, COALESCE(SUM(pr.approved_headcount), 0) AS headcount')->first();

            return [
                'required_fte' => (int) ($planned->n ?? 0) === 0 ? null : round((float) $planned->fte, 2),
                'approved_headcount' => (int) ($planned->n ?? 0) === 0 ? null : (int) $planned->headcount,
                'approved_positions' => (int) ($approved->positions ?? 0),
                'approved_slots' => (int) ($approved->slots ?? 0),
                'capacity' => $sum('capacity'),
                'occupied' => $sum('occupied'),
                'vacant' => $sum('vacant'),
                'employee_count' => WorkforceQueries::headcount($context),
                'vacancy_rate' => $this->percent($sum('vacant'), $sum('capacity')),
                'over' => $sum('over'),
            ];
        });
    }

    private function figures(SituationContext $context): SituationPanel
    {
        $f = $this->fiveFigures($context);

        return SituationPanel::kpis('workforce_figures', $this->title('workforce_figures'), $this->source('workforce'), [
            $this->kpi('required_fte', $f['required_fte']),
            $this->kpi('approved_headcount', $f['approved_headcount']),
            $this->kpi('approved_positions', $f['approved_positions']),
            $this->kpi('approved_slots', $f['approved_slots']),
            $this->kpi('capacity', $f['capacity']),
            $this->kpi('occupied_positions', $f['occupied']),
            $this->kpi('vacant_positions', $f['vacant']),
            $this->kpi('employee_count', $f['employee_count']),
            $this->kpi('vacancy_rate', $f['vacancy_rate'], ['unit' => '%']),
        ], $this->hint('workforce_figures'));
    }

    /** One row per roll-up row, in name order: a breakdown, not a ranking. */
    private function byOrganization(SituationContext $context): SituationPanel
    {
        $capacity = WorkforceQueries::capacityByOrganization($context);
        $columns = [
            'positions' => $this->rolledUp($context, array_map(fn (array $r): int => $r['positions'], $capacity)),
            'capacity' => $this->rolledUp($context, array_map(fn (array $r): int => $r['capacity'], $capacity)),
            'occupied_positions' => $this->rolledUp($context, array_map(fn (array $r): int => $r['occupied'], $capacity)),
            'vacant_positions' => $this->rolledUp($context, array_map(fn (array $r): int => $r['vacant'], $capacity)),
            'employee_count' => $this->rolledUp($context, WorkforceQueries::headcountByOrganization($context)),
        ];
        $order = $columns['positions'];
        $rows = [];
        foreach ($order['ids'] as $i => $id) {
            $row = ['organization' => $order['categories'][$i]];
            foreach ($columns as $name => $data) {
                $byId = array_combine($data['ids'], $data['values']) ?: [];
                $row[$name] = (int) ($byId[$id] ?? 0);
            }
            $row['vacancy_rate'] = $this->percent($row['vacant_positions'], $row['capacity']);
            $rows[] = $row;
        }

        return SituationPanel::table('workforce_by_organization', $this->title('workforce_by_organization'), $this->hint('workforce_by_organization'), $this->source('workforce'), [
            $this->column('organization', 'label'),
            $this->column('positions'),
            $this->column('capacity'),
            $this->column('occupied_positions'),
            $this->column('vacant_positions'),
            $this->column('employee_count'),
            $this->column('vacancy_rate', 'percent'),
        ], $rows, span: 3);
    }

    private function occupations(SituationContext $context): SituationPanel
    {
        $counts = WorkforceQueries::assignments($context)
            ->leftJoin('positions as p', 'p.id', '=', 'ea.position_id')
            ->selectRaw('COALESCE('.$this->idText('p.occupation_id').", '__none') AS bucket, COUNT(*) AS total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->map(fn ($v): int => (int) $v)
            ->all();
        $top = $this->topWithOther($counts);
        $names = Occupation::query()->whereIn('id', array_filter(array_keys($top), fn ($k): bool => ! str_starts_with((string) $k, '__')))->get(['id', 'name_en', 'name_am'])->keyBy(fn ($o): string => (string) $o->id);
        $categories = array_map(fn ($key): array => match ((string) $key) {
            '__none' => SituationLabel::key('situationRoom.values.common.not_linked'),
            '__other' => SituationLabel::key('situationRoom.values.common.other'),
            default => SituationLabel::names($names[$key]->name_en ?? null, $names[$key]->name_am ?? null),
        }, array_keys($top));

        return $this->chart('workforce_occupations', 'hbar', $this->source('workforce'), $categories, [
            ['key' => 'employee_count', 'label' => SituationLabel::key(self::PREFIX.'kpi.employee_count'), 'values' => $this->suppressSeries(array_values($top))],
        ], span: 2);
    }

    private function grades(SituationContext $context): SituationPanel
    {
        $counts = WorkforceQueries::assignments($context)
            ->leftJoin('positions as p', 'p.id', '=', 'ea.position_id')
            ->selectRaw("COALESCE(NULLIF(TRIM(p.grade_level), ''), '__none') AS bucket, COUNT(*) AS total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->map(fn ($v): int => (int) $v)
            ->all();
        $top = $this->topWithOther($counts);
        $categories = array_map(fn ($key): array|string => match ((string) $key) {
            '__none' => SituationLabel::key('situationRoom.values.common.not_recorded'),
            '__other' => SituationLabel::key('situationRoom.values.common.other'),
            default => (string) $key,
        }, array_keys($top));

        return $this->chart('workforce_grades', 'bar', $this->source('workforce'), $categories, [
            ['key' => 'employee_count', 'label' => SituationLabel::key(self::PREFIX.'kpi.employee_count'), 'values' => $this->suppressSeries(array_values($top))],
        ]);
    }

    /** @return array{multiple_current_assignments: int, active_without_assignment: int, positions_over_capacity: int} */
    public function anomalyCounts(SituationContext $context): array
    {
        return app(SituationMemo::class)->remember(SituationMemo::key($context, 'intelligence:workforce:anomalies'), fn (): array => [
            'multiple_current_assignments' => (int) DB::query()->fromSub(
                WorkforceQueries::assignments($context)->selectRaw('ea.employee_id')->groupBy('ea.employee_id')->havingRaw('COUNT(*) > 1'), 'm')->count(),
            'active_without_assignment' => $this->activeWithoutAssignment($context),
            'positions_over_capacity' => $this->positionsOverCapacity($context),
        ]);
    }

    private function anomalies(SituationContext $context): SituationPanel
    {
        $a = $this->anomalyCounts($context);

        return SituationPanel::kpis('workforce_anomalies', $this->title('workforce_anomalies'), $this->source('workforce'), [
            $this->kpi('multiple_current_assignments', $a['multiple_current_assignments'], ['href' => $this->link('employees.index')]),
            $this->kpi('active_without_assignment', $a['active_without_assignment'], ['href' => $this->link('employees.index')]),
            $this->kpi('positions_over_capacity', $a['positions_over_capacity'], ['href' => $this->link('positions.index')]),
        ], $this->hint('workforce_anomalies'));
    }

    /**
     * Active employees with no current active assignment, attributed to the
     * scope through ANY assignment they ever had (an employee with no
     * assignment at all belongs to no organization and is only counted for
     * an unrestricted viewer).
     */
    private function activeWithoutAssignment(SituationContext $context): int
    {
        $query = DB::table('employees as e')
            ->where('e.status', EmployeeStatus::Active->value)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('employee_assignments as cur')
                ->whereColumn('cur.employee_id', 'e.id')->where('cur.is_current', true)->where('cur.assignment_status', AssignmentStatus::Active->value));

        if ($context->scope->organizationIds !== null) {
            $query->whereExists(function ($q) use ($context): void {
                $q->selectRaw('1')->from('employee_assignments as any')->whereColumn('any.employee_id', 'e.id');
                $context->scope->apply($q, 'any.organization_id');
            });
        }

        return (int) $query->count();
    }

    /** Positions holding more current occupants than approved slots (rule D-2: one slot without an establishment). */
    private function positionsOverCapacity(SituationContext $context): int
    {
        $today = CarbonImmutable::today()->toDateString();
        $establishment = DB::table('position_establishments')
            ->selectRaw('position_id, SUM(approved_slots) AS slots')
            ->where('status', EstablishmentStatus::Approved->value)->whereNull('deleted_at')
            ->whereRaw('DATE(effective_from) <= ?', [$today])
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereRaw('DATE(effective_to) >= ?', [$today]))
            ->groupBy('position_id');
        $occupants = DB::table('employee_assignments')
            ->selectRaw('position_id, COUNT(*) AS n')
            ->where('is_current', true)->where('assignment_status', AssignmentStatus::Active->value)->whereNotNull('position_id')
            ->groupBy('position_id');
        $query = DB::table('positions as p')
            ->leftJoinSub($establishment, 'est', 'est.position_id', '=', 'p.id')
            ->joinSub($occupants, 'occ', 'occ.position_id', '=', 'p.id')
            ->whereNull('p.deleted_at')->where('p.is_active', true)
            ->whereRaw('occ.n > CASE WHEN COALESCE(est.slots, 0) > 0 THEN est.slots ELSE 1 END');
        $context->scope->apply($query, 'p.organization_id');

        return (int) $query->count();
    }

    /** @return array{positions: int, without_jd: int, without_competency: int} */
    public function designGapCounts(SituationContext $context): array
    {
        return app(SituationMemo::class)->remember(SituationMemo::key($context, 'intelligence:workforce:design_gaps'), function () use ($context): array {
            $today = CarbonImmutable::today()->toDateString();

            return [
                'positions' => (int) $this->activePositions($context)->count(),
                'without_jd' => (int) $this->activePositions($context)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('job_descriptions as jd')
                    ->whereColumn('jd.position_id', 'p.id')->whereNull('jd.deleted_at')
                    ->whereIn('jd.status', [OrganizationDesignStatus::Published->value, OrganizationDesignStatus::Superseded->value])
                    ->whereRaw('DATE(jd.effective_from) <= ?', [$today])
                    ->where(fn ($w) => $w->whereNull('jd.effective_to')->orWhereRaw('DATE(jd.effective_to) >= ?', [$today])))->count(),
                'without_competency' => (int) $this->activePositions($context)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('position_competency_requirements as cr')
                    ->whereColumn('cr.position_id', 'p.id'))->count(),
            ];
        });
    }

    public function activePositions(SituationContext $context): Builder
    {
        $query = DB::table('positions as p')->whereNull('p.deleted_at')->where('p.is_active', true);
        $context->scope->apply($query, 'p.organization_id');
        if (($unit = $context->filters->get('organization_unit_id')) !== null) {
            $query->where('p.organization_unit_id', $unit);
        }

        return $query;
    }

    private function designGaps(SituationContext $context): SituationPanel
    {
        $g = $this->designGapCounts($context);

        return SituationPanel::kpis('workforce_design_gaps', $this->title('workforce_design_gaps'), $this->source('position_design'), [
            $this->kpi('positions', $g['positions']),
            $this->kpi('positions_without_jd', $g['without_jd'], ['href' => $this->link('positions.index')]),
            $this->kpi('positions_without_competency', $g['without_competency'], ['href' => $this->link('positions.index')]),
            $this->kpi('jd_coverage', $this->percent($g['positions'] - $g['without_jd'], $g['positions']), ['unit' => '%']),
        ], $this->hint('workforce_design_gaps'));
    }

    /** Completed transfers with at least one end inside the scope. */
    private function completedTransfers(SituationContext $context): Builder
    {
        $query = DB::table('employee_transfers as t')->where('t.status', TransferStatus::Completed->value);
        $ids = $context->scope->organizationIds;
        if ($ids !== null) {
            $ids === []
                ? $query->whereRaw('1 = 0')
                : $query->where(fn ($q) => $q->whereIn('t.from_organization_id', $ids)->orWhereIn('t.to_organization_id', $ids));
        }

        return $query;
    }

    /**
     * Inbound arrives from outside the scope, outbound leaves it, internal
     * moves inside it. An unrestricted viewer has no outside: everything is internal.
     *
     * @return array{inbound: int, outbound: int, internal: int}
     */
    public function transferDirections(SituationContext $context, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $completed = $this->completedTransfers($context)->whereBetween('t.completed_at', [$from->toDateTimeString(), $to->toDateTimeString()]);
        $ids = $context->scope->organizationIds;
        if ($ids === null) {
            return ['inbound' => 0, 'outbound' => 0, 'internal' => (clone $completed)->count()];
        }

        return [
            'inbound' => (clone $completed)->whereIn('t.to_organization_id', $ids)->whereNotIn('t.from_organization_id', $ids)->count(),
            'outbound' => (clone $completed)->whereIn('t.from_organization_id', $ids)->whereNotIn('t.to_organization_id', $ids)->count(),
            'internal' => (clone $completed)->whereIn('t.from_organization_id', $ids)->whereIn('t.to_organization_id', $ids)->count(),
        ];
    }

    private function transfers(SituationContext $context): SituationPanel
    {
        $period = $context->period();
        $d = $this->transferDirections($context, $period->from, $period->to);

        return SituationPanel::kpis('workforce_transfers', $this->title('workforce_transfers'), $this->source('transfers'), [
            $this->kpi('transfers_inbound', $d['inbound']),
            $this->kpi('transfers_outbound', $d['outbound']),
            $this->kpi('transfers_internal', $d['internal']),
            $this->kpi('transfers_net', $d['inbound'] - $d['outbound']),
        ], $this->hint('workforce_transfers'));
    }

    private function transfersByOrganization(SituationContext $context): SituationPanel
    {
        $period = $context->period();
        $completed = fn (): Builder => $this->completedTransfers($context)->whereBetween('t.completed_at', $this->bounds($period));
        $arrivals = $this->rolledUp($context, $this->inScopeOnly($context, $this->countBy($completed(), 't.to_organization_id')));
        $departures = $this->rolledUp($context, $this->inScopeOnly($context, $this->countBy($completed(), 't.from_organization_id')));

        return $this->chart('workforce_transfers_by_organization', 'hbar', $this->source('transfers'), $arrivals['categories'], [
            ['key' => 'arrivals', 'label' => SituationLabel::key('situationRoom.series.arrivals'), 'values' => $arrivals['values']],
            ['key' => 'departures', 'label' => SituationLabel::key('situationRoom.series.departures'), 'values' => $departures['values']],
        ], span: 2);
    }

    /** Assignments that started in each of the last twelve complete months plus the current one. */
    private function assignmentStarts(SituationContext $context): SituationPanel
    {
        $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'));
        $query = DB::table('employee_assignments as ea');
        $context->scope->apply($query, 'ea.organization_id');
        if (($unit = $context->filters->get('organization_unit_id')) !== null) {
            $query->where('ea.organization_unit_id', $unit);
        }
        $trend = $this->series($query, 'ea.effective_from', $now->subMonthsNoOverflow(11)->startOfMonth(), $now->endOfDay(), SqlDateBucket::MONTH, true);

        return $this->chart('workforce_assignment_starts', 'line', $this->source('workforce'), $trend['categories'], [
            ['key' => 'assignment_starts', 'label' => SituationLabel::key(self::PREFIX.'kpi.assignment_starts'), 'values' => $trend['values']],
        ], span: 2);
    }

    /**
     * Outbound transfers of the period, normalised to a 30-day rate, against
     * the monthly outbound counts of the twelve months before the period.
     *
     * @return array{current: int, current_rate: float, history: list<int>}|null
     */
    private function outflowBaseline(SituationContext $context): ?array
    {
        $ids = $context->scope->organizationIds;
        if ($ids === null || $ids === []) {
            return null;
        }
        $period = $context->period();
        $current = $this->transferDirections($context, $period->from, $period->to)['outbound'];
        $days = max(1, (int) $period->from->diffInDays($period->to) + 1);
        $outbound = $this->completedTransfers($context)->whereIn('t.from_organization_id', $ids)->whereNotIn('t.to_organization_id', $ids);
        $history = $this->series($outbound, 't.completed_at', $period->from->subMonthsNoOverflow(12)->startOfMonth(), $period->from->subMicrosecond(), SqlDateBucket::MONTH);

        return ['current' => $current, 'current_rate' => round($current * 30 / $days, 2), 'history' => $history['values']];
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function inScopeOnly(SituationContext $context, array $counts): array
    {
        $ids = $context->scope->organizationIds;

        return $ids === null ? $counts : array_intersect_key($counts, array_flip($ids));
    }
}
