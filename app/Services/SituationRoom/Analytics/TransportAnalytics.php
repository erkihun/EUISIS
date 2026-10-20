<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Enums\Transport\TransportTripStatus;
use App\Models\Provider;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Transport: verified boardings, riders, trips run and refused scans for
 * the employees of the scope (by the employee's current organization).
 * Trips follow the route's organization; city-wide routes count for every
 * scope. Counts only; no employee appears and no amount is shown here.
 */
final class TransportAnalytics extends SituationAnalytics
{
    public const SOURCE = 'situationRoom.sources.transport';

    public function panels(SituationContext $context): array
    {
        return [
            'transport_kpis' => fn (): SituationPanel => $this->kpis($context),
            'transport_trend' => fn (): SituationPanel => $this->trendPanel($context),
            'transport_by_organization' => fn (): SituationPanel => $this->byOrganization($context),
            'transport_status' => fn (): SituationPanel => $this->status($context),
            'transport_providers' => fn (): SituationPanel => $this->providers($context),
            'transport_refusals' => fn (): SituationPanel => $this->refusals($context),
            'transport_trips' => fn (): SituationPanel => $this->trips($context),
        ];
    }

    /** Scans of employees in scope, by the employee's current assignment. */
    private function scans(SituationContext $context): Builder
    {
        $query = DB::table('transport_transactions as t')
            ->join('employee_assignments as ea', fn ($join) => $join->on('ea.employee_id', '=', 't.employee_id')->where('ea.is_current', true));
        $context->scope->apply($query, 'ea.organization_id');

        return $this->inPeriod($query, 't.transaction_date', $context->period(), true);
    }

    private function verified(SituationContext $context): Builder
    {
        return $this->scans($context)->where('t.status', 'accepted')->whereNull('t.voided_at');
    }

    /** Trips on routes serving the scope: the route's organization, or any city-wide route. */
    private function tripsInScope(SituationContext $context): Builder
    {
        $query = DB::table('transport_trips as tr')->join('transport_routes as r', 'r.id', '=', 'tr.transport_route_id');
        $ids = $context->scope->organizationIds;
        if ($ids !== null) {
            $query->where(fn ($w) => $w->whereNull('r.assigned_organization_id')->orWhereIn('r.assigned_organization_id', $ids));
        }

        return $this->inPeriod($query, 'tr.trip_date', $context->period(), true);
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        $verified = $this->verified($context);
        $trips = $this->tripsInScope($context);
        $completed = (clone $trips)->where('tr.status', TransportTripStatus::Completed->value);
        $seats = (int) (clone $completed)->sum('tr.capacity');
        $boardedOnCompleted = (int) (clone $completed)->sum('tr.boarded_count');

        return SituationPanel::kpis('transport_kpis', $this->title('transport_kpis'), self::SOURCE, [
            $this->kpi('verified_boardings', (clone $verified)->count()),
            $this->kpi('transport_riders', (clone $verified)->distinct()->count('t.employee_id')),
            $this->kpi('transport_scans_refused', (clone $this->scans($context))->where('t.status', 'rejected')->count()),
            $this->kpi('trips_completed', (clone $completed)->count()),
            $this->kpi('trips_cancelled', (clone $trips)->whereIn('tr.status', [TransportTripStatus::Cancelled->value, TransportTripStatus::Failed->value])->count()),
            $this->kpi('seat_utilization', $seats > 0 ? round(100 * $boardedOnCompleted / $seats, 1) : null, ['unit' => '%']),
        ]);
    }

    private function trendPanel(SituationContext $context): SituationPanel
    {
        $query = DB::table('transport_transactions as t')->where('t.status', 'accepted')->whereNull('t.voided_at')
            ->join('employee_assignments as ea', fn ($join) => $join->on('ea.employee_id', '=', 't.employee_id')->where('ea.is_current', true));
        $context->scope->apply($query, 'ea.organization_id');
        $trend = $this->trend($query, 't.transaction_date', $context->period(), true);

        return $this->chart('transport_trend', 'line', self::SOURCE, $trend['categories'], [
            ['key' => 'boardings', 'label' => SituationLabel::key('situationRoom.series.boardings'), 'values' => $trend['values']],
        ], span: 2);
    }

    private function byOrganization(SituationContext $context): SituationPanel
    {
        $rolled = $this->rolledUp($context, $this->countBy($this->verified($context), 'ea.organization_id'));

        return $this->chart('transport_by_organization', 'hbar', self::SOURCE, $rolled['categories'], [
            ['key' => 'boardings', 'label' => SituationLabel::key('situationRoom.series.boardings'), 'values' => $rolled['values']],
        ], span: 2);
    }

    private function status(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->scans($context), "CASE WHEN t.voided_at IS NOT NULL THEN 'voided' ELSE t.status END");
        $buckets = ['accepted', 'rejected', 'voided'];

        return $this->chart('transport_status', 'donut', self::SOURCE, $this->valueLabels('transport_status', $buckets), [
            ['key' => 'scans', 'label' => SituationLabel::key('situationRoom.series.scans'), 'values' => array_map(fn (string $b): int => $counts[$b] ?? 0, $buckets)],
        ]);
    }

    private function providers(SituationContext $context): SituationPanel
    {
        $top = $this->topWithOther($this->countBy($this->verified($context), 't.provider_id'));
        $names = Provider::withTrashed()->whereIn('id', array_filter(array_keys($top), fn ($k): bool => ! str_starts_with((string) $k, '__')))->get(['id', 'name_en', 'name_am'])->keyBy(fn ($p): string => (string) $p->id);

        return $this->chart('transport_providers', 'hbar', self::SOURCE, array_map(fn ($key): array => match ((string) $key) {
            '__other' => SituationLabel::key('situationRoom.values.common.other'),
            default => SituationLabel::names($names[$key]->name_en ?? null, $names[$key]->name_am ?? null),
        }, array_keys($top)), [
            ['key' => 'boardings', 'label' => SituationLabel::key('situationRoom.series.boardings'), 'values' => array_values($top)],
        ]);
    }

    private function refusals(SituationContext $context): SituationPanel
    {
        $top = $this->topWithOther($this->countBy($this->scans($context)->where('t.status', 'rejected'), "COALESCE(NULLIF(TRIM(t.result_code), ''), '__none')"));

        // Result codes are the boarding engine's vocabulary; the page shows the transport dictionary's words for them.
        $categories = array_map(fn ($k): array => match ((string) $k) {
            '__other' => SituationLabel::key('situationRoom.values.common.other'),
            '__none' => SituationLabel::key('situationRoom.values.common.not_recorded'),
            default => SituationLabel::key('transport.reasons.'.$k),
        }, array_keys($top));

        return $this->chart('transport_refusals', 'hbar', self::SOURCE, $categories, [
            ['key' => 'scans', 'label' => SituationLabel::key('situationRoom.series.scans'), 'values' => array_values($top)],
        ]);
    }

    private function trips(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->tripsInScope($context), 'tr.status');
        $buckets = TransportTripStatus::values();

        return $this->chart('transport_trips', 'donut', self::SOURCE, $this->valueLabels('transport_trip_status', $buckets), [
            ['key' => 'trips', 'label' => SituationLabel::key('situationRoom.series.trips'), 'values' => array_map(fn (string $b): int => $counts[$b] ?? 0, $buckets)],
        ]);
    }
}
