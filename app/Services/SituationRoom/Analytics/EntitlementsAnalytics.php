<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Enums\TransactionStatus;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Entitlements\Contracts\Data\EntitlementStatus;

/**
 * Employee entitlements and the services drawn on them: who holds a live
 * entitlement, what was authorised or refused in the period, and what it
 * added up to. An entitlement is placed where its holder is placed. Counts
 * and totals only; no employee is named.
 */
final class EntitlementsAnalytics extends SituationAnalytics
{
    use ScopesByEmployee;

    public const SOURCE = 'situationRoom.sources.entitlements';

    public function panels(SituationContext $context): array
    {
        return [
            'entitlements_kpis' => fn (): SituationPanel => $this->kpis($context),
            'entitlement_status' => fn (): SituationPanel => $this->status($context),
            'transactions_by_service_type' => fn (): SituationPanel => $this->byServiceType($context),
            'transactions_trend' => fn (): SituationPanel => $this->transactionsTrend($context),
            'transactions_by_organization' => fn (): SituationPanel => $this->byOrganization($context),
        ];
    }

    private function entitlements(SituationContext $context): Builder
    {
        return DB::table('entitlements as en')->whereIn('en.employee_id', $this->employeesInScope($context));
    }

    private function transactions(SituationContext $context): Builder
    {
        return $this->inPeriod(DB::table('service_transactions as tx')->whereIn('tx.employee_id', $this->employeesInScope($context)), 'tx.occurred_at', $context->period());
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        $served = [TransactionStatus::Authorized->value, TransactionStatus::Settled->value];

        return SituationPanel::kpis('entitlements_kpis', $this->title('entitlements_kpis'), self::SOURCE, [
            $this->kpi('entitlements_active', $this->entitlements($context)->where('en.status', EntitlementStatus::Active->value)->count()),
            $this->kpi('employees_entitled', $this->entitlements($context)->where('en.status', EntitlementStatus::Active->value)->distinct()->count('en.employee_id')),
            $this->kpi('service_transactions', $this->transactions($context)->count()),
            $this->kpi('service_transactions_denied', $this->transactions($context)->where('tx.status', TransactionStatus::Denied->value)->count()),
            $this->kpi('service_amount', round((float) $this->transactions($context)->whereIn('tx.status', $served)->sum('tx.amount'), 2), ['unit' => 'ETB']),
            $this->kpi('entitlements_exhausted', $this->entitlements($context)->where('en.status', EntitlementStatus::Exhausted->value)->count()),
        ]);
    }

    private function status(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->entitlements($context), 'en.status');
        $buckets = array_column(EntitlementStatus::cases(), 'value');

        return $this->chart('entitlement_status', 'donut', self::SOURCE, $this->valueLabels('entitlement_status', $buckets), [
            ['key' => 'entitlements', 'label' => SituationLabel::key('situationRoom.series.entitlements'), 'values' => array_map(fn (string $b): int => $counts[$b] ?? 0, $buckets)],
        ]);
    }

    /** Transactions of the period by service type, with the type's own bilingual name. */
    private function byServiceType(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->transactions($context), 'tx.service_type_id');
        $denied = $this->countBy($this->transactions($context)->where('tx.status', TransactionStatus::Denied->value), 'tx.service_type_id');
        arsort($counts);
        $names = DB::table('service_types')->whereIn('id', array_keys($counts))->get(['id', 'name_en', 'name_am'])->keyBy(fn ($t): string => (string) $t->id);
        $ids = array_keys($counts);

        return $this->chart('transactions_by_service_type', 'bar', self::SOURCE, array_map(fn ($id): array => ['en' => $names[(string) $id]->name_en ?? null, 'am' => $names[(string) $id]->name_am ?? null], $ids), [
            ['key' => 'service_transactions', 'label' => SituationLabel::key('situationRoom.kpi.service_transactions'), 'values' => array_values($counts)],
            ['key' => 'service_transactions_denied', 'label' => SituationLabel::key('situationRoom.kpi.service_transactions_denied'), 'values' => array_map(fn ($id): int => $denied[(string) $id] ?? 0, $ids)],
        ]);
    }

    private function transactionsTrend(SituationContext $context): SituationPanel
    {
        $trend = $this->trend(DB::table('service_transactions as tx')->whereIn('tx.employee_id', $this->employeesInScope($context)), 'tx.occurred_at', $context->period());

        return $this->chart('transactions_trend', 'line', self::SOURCE, $trend['categories'], [
            ['key' => 'service_transactions', 'label' => SituationLabel::key('situationRoom.kpi.service_transactions'), 'values' => $trend['values']],
        ], span: 2);
    }

    private function byOrganization(SituationContext $context): SituationPanel
    {
        $query = $this->joinAssignment($this->inPeriod(DB::table('service_transactions as tx'), 'tx.occurred_at', $context->period()), 'tx.employee_id', $context);
        $rolled = $this->rolledUp($context, $this->countBy($query, 'ea.organization_id'));

        return $this->chart('transactions_by_organization', 'hbar', self::SOURCE, $rolled['categories'], [
            ['key' => 'service_transactions', 'label' => SituationLabel::key('situationRoom.kpi.service_transactions'), 'values' => $this->suppressSeries($rolled['values'])],
        ], span: 2);
    }
}
