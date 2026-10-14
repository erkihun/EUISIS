<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Enums\TransferStatus;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Employee Movement: transfers into, out of and within the scope, and
 * employment-status changes (retirement, termination ...) recorded in the
 * period. A transfer is "inbound" when it arrives from outside the scope,
 * "outbound" when it leaves it and "internal" when both ends are inside.
 */
final class MovementAnalytics extends SituationAnalytics
{
    public const SOURCE = 'situationRoom.sources.transfers';

    public const PENDING = ['submitted', 'current_organization_confirmed', 'receiving_organization_confirmed', 'approved'];

    public function panels(SituationContext $context): array
    {
        return [
            'movement_kpis' => fn (): SituationPanel => $this->kpis($context),
            'movement_trend' => fn (): SituationPanel => $this->trendPanel($context),
            'movement_by_organization' => fn (): SituationPanel => $this->byOrganization($context),
            'movement_status' => fn (): SituationPanel => $this->status($context),
            'employment_status_changes' => fn (): SituationPanel => $this->statusChanges($context),
        ];
    }

    /** Transfers with at least one end inside the scope. */
    private function touching(SituationContext $context): Builder
    {
        $query = DB::table('employee_transfers as t');
        $ids = $context->scope->organizationIds;

        if ($ids !== null) {
            $ids === []
                ? $query->whereRaw('1 = 0')
                : $query->where(fn ($q) => $q->whereIn('t.from_organization_id', $ids)->orWhereIn('t.to_organization_id', $ids));
        }

        return $query;
    }

    /** @return array{inbound: int, outbound: int, internal: int} */
    private function directions(SituationContext $context): array
    {
        $completed = $this->inPeriod($this->touching($context), 't.completed_at', $context->period())->where('t.status', TransferStatus::Completed->value);
        $ids = $context->scope->organizationIds;

        if ($ids === null) {
            return ['inbound' => 0, 'outbound' => 0, 'internal' => (clone $completed)->count()];
        }

        return [
            'inbound' => (clone $completed)->whereIn('t.to_organization_id', $ids)->where(fn ($q) => $q->whereNull('t.from_organization_id')->orWhereNotIn('t.from_organization_id', $ids))->count(),
            'outbound' => (clone $completed)->whereIn('t.from_organization_id', $ids)->where(fn ($q) => $q->whereNull('t.to_organization_id')->orWhereNotIn('t.to_organization_id', $ids))->count(),
            'internal' => (clone $completed)->whereIn('t.from_organization_id', $ids)->whereIn('t.to_organization_id', $ids)->count(),
        ];
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        $directions = $this->directions($context);

        return SituationPanel::kpis('movement_kpis', $this->title('movement_kpis'), self::SOURCE, [
            $this->kpi('transfers_completed', array_sum($directions)),
            $this->kpi('transfers_inbound', $directions['inbound']),
            $this->kpi('transfers_outbound', $directions['outbound']),
            $this->kpi('transfers_internal', $directions['internal']),
            $this->kpi('transfers_pending', (clone $this->touching($context))->whereIn('t.status', self::PENDING)->count()),
            $this->kpi('position_moves', $this->inPeriod($this->scoped(DB::table('position_movements as pm'), $context, 'pm.organization_id'), 'pm.moved_at', $context->period())->count()),
        ]);
    }

    private function trendPanel(SituationContext $context): SituationPanel
    {
        $trend = $this->trend($this->touching($context)->where('t.status', TransferStatus::Completed->value), 't.completed_at', $context->period());

        return $this->chart('movement_trend', 'line', self::SOURCE, $trend['categories'], [
            ['key' => 'transfers_completed', 'label' => SituationLabel::key('situationRoom.kpi.transfers_completed'), 'values' => $trend['values']],
        ], span: 2);
    }

    private function byOrganization(SituationContext $context): SituationPanel
    {
        $completed = fn () => $this->inPeriod($this->touching($context), 't.completed_at', $context->period())->where('t.status', TransferStatus::Completed->value);
        $arrivals = $this->rolledUp($context, $this->inScopeOnly($context, $this->countBy($completed(), 't.to_organization_id')));
        $departures = $this->rolledUp($context, $this->inScopeOnly($context, $this->countBy($completed(), 't.from_organization_id')));

        return $this->chart('movement_by_organization', 'hbar', self::SOURCE, $arrivals['categories'], [
            ['key' => 'arrivals', 'label' => SituationLabel::key('situationRoom.series.arrivals'), 'values' => $arrivals['values']],
            ['key' => 'departures', 'label' => SituationLabel::key('situationRoom.series.departures'), 'values' => $departures['values']],
        ], span: 2);
    }

    private function status(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->inPeriod($this->touching($context), 't.created_at', $context->period()), 't.status');
        $buckets = array_column(TransferStatus::cases(), 'value');

        return $this->chart('movement_status', 'donut', self::SOURCE, $this->valueLabels('transfer_status', $buckets), [
            ['key' => 'transfers', 'label' => SituationLabel::key('situationRoom.series.transfers'), 'values' => array_map(fn (string $b): int => $counts[$b] ?? 0, $buckets)],
        ]);
    }

    /**
     * Employment-status changes that took effect in the period, attributed to
     * the organization the employee was assigned to on that day — so a
     * retirement counts where the person worked, even after the assignment
     * closed.
     */
    private function statusChanges(SituationContext $context): SituationPanel
    {
        $query = DB::table('employment_status_histories as h')
            ->whereBetween('h.effective_from', $this->bounds($context->period(), true))
            ->whereExists(function ($q) use ($context): void {
                $q->selectRaw('1')->from('employee_assignments as ea')
                    ->whereColumn('ea.employee_id', 'h.employee_id')
                    ->whereColumn('ea.effective_from', '<=', 'h.effective_from')
                    ->where(fn ($w) => $w->whereNull('ea.effective_to')->orWhereColumn('ea.effective_to', '>=', 'h.effective_from'));
                $context->scope->apply($q, 'ea.organization_id');
            });
        $counts = $this->countBy($query, 'h.status');
        ksort($counts);

        return $this->chart('employment_status_changes', 'bar', 'situationRoom.sources.employment_status', $this->valueLabels('employee_status', array_keys($counts)), [
            ['key' => 'changes', 'label' => SituationLabel::key('situationRoom.series.changes'), 'values' => array_values($counts)],
        ]);
    }

    private function scoped(Builder $query, SituationContext $context, string $column): Builder
    {
        return $context->scope->apply($query, $column);
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
