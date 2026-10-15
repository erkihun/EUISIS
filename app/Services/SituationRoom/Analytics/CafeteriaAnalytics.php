<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Enums\CafeteriaTransactionStatus;
use App\Models\CafeteriaProvider;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cafeteria: meals served, scans refused and subsidy applied for the
 * employees of the scope (by the organization recorded on each
 * transaction). Amounts are totals; no employee appears.
 */
final class CafeteriaAnalytics extends SituationAnalytics
{
    public const SOURCE = 'situationRoom.sources.cafeteria';

    public function panels(SituationContext $context): array
    {
        return [
            'cafeteria_kpis' => fn (): SituationPanel => $this->kpis($context),
            'cafeteria_trend' => fn (): SituationPanel => $this->trendPanel($context),
            'cafeteria_by_organization' => fn (): SituationPanel => $this->byOrganization($context),
            'cafeteria_status' => fn (): SituationPanel => $this->status($context),
            'cafeteria_providers' => fn (): SituationPanel => $this->providers($context),
            'cafeteria_refusals' => fn (): SituationPanel => $this->refusals($context),
        ];
    }

    private function base(SituationContext $context): Builder
    {
        $query = DB::table('cafeteria_transactions as c');
        $context->scope->apply($query, 'c.employee_organization_id');

        return $this->inPeriod($query, 'c.transaction_date', $context->period(), true);
    }

    private function accepted(SituationContext $context): Builder
    {
        return $this->base($context)->where('c.status', CafeteriaTransactionStatus::Accepted->value);
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        $accepted = $this->accepted($context);

        return SituationPanel::kpis('cafeteria_kpis', $this->title('cafeteria_kpis'), self::SOURCE, [
            $this->kpi('meals_served', (clone $accepted)->count()),
            $this->kpi('people_served', (clone $accepted)->distinct()->count('c.employee_id')),
            $this->kpi('scans_refused', (clone $this->base($context))->where('c.status', CafeteriaTransactionStatus::Rejected->value)->count()),
            $this->kpi('scans_pending_review', (clone $this->base($context))->where('c.status', CafeteriaTransactionStatus::PendingReview->value)->count()),
            $this->kpi('subsidy_applied', round((float) (clone $accepted)->sum('c.subsidy_amount_applied'), 2), ['unit' => 'ETB']),
            $this->kpi('employee_contribution', round((float) (clone $accepted)->sum('c.employee_contribution_applied'), 2), ['unit' => 'ETB']),
        ]);
    }

    private function trendPanel(SituationContext $context): SituationPanel
    {
        $query = DB::table('cafeteria_transactions as c')->where('c.status', CafeteriaTransactionStatus::Accepted->value);
        $context->scope->apply($query, 'c.employee_organization_id');
        $trend = $this->trend($query, 'c.transaction_date', $context->period(), true);

        return $this->chart('cafeteria_trend', 'line', self::SOURCE, $trend['categories'], [
            ['key' => 'meals_served', 'label' => SituationLabel::key('situationRoom.kpi.meals_served'), 'values' => $trend['values']],
        ], span: 2);
    }

    private function byOrganization(SituationContext $context): SituationPanel
    {
        $rolled = $this->rolledUp($context, $this->countBy($this->accepted($context), 'c.employee_organization_id'));

        return $this->chart('cafeteria_by_organization', 'hbar', self::SOURCE, $rolled['categories'], [
            ['key' => 'meals_served', 'label' => SituationLabel::key('situationRoom.kpi.meals_served'), 'values' => $rolled['values']],
        ], span: 2);
    }

    private function status(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->base($context), 'c.status');
        $buckets = array_column(CafeteriaTransactionStatus::cases(), 'value');

        return $this->chart('cafeteria_status', 'donut', self::SOURCE, $this->valueLabels('cafeteria_status', $buckets), [
            ['key' => 'scans', 'label' => SituationLabel::key('situationRoom.series.scans'), 'values' => array_map(fn (string $b): int => $counts[$b] ?? 0, $buckets)],
        ]);
    }

    private function providers(SituationContext $context): SituationPanel
    {
        $top = $this->topWithOther($this->countBy($this->accepted($context), "COALESCE(CAST(c.cafeteria_provider_id AS VARCHAR(64)), '__none')"));
        $names = CafeteriaProvider::withTrashed()->whereIn('id', array_filter(array_keys($top), fn ($k): bool => ! str_starts_with((string) $k, '__')))->get(['id', 'name_en', 'name_am'])->keyBy(fn ($p): string => (string) $p->id);

        return $this->chart('cafeteria_providers', 'hbar', self::SOURCE, array_map(fn ($key): array => match ((string) $key) {
            '__none' => SituationLabel::key('situationRoom.values.common.not_recorded'),
            '__other' => SituationLabel::key('situationRoom.values.common.other'),
            default => SituationLabel::names($names[$key]->name_en ?? null, $names[$key]->name_am ?? null),
        }, array_keys($top)), [
            ['key' => 'meals_served', 'label' => SituationLabel::key('situationRoom.kpi.meals_served'), 'values' => array_values($top)],
        ]);
    }

    private function refusals(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->base($context)->where('c.status', CafeteriaTransactionStatus::Rejected->value), "COALESCE(NULLIF(TRIM(c.blocked_reason), ''), '__none')");
        $top = $this->topWithOther($counts);

        // Refusal reasons are recorded as text by the scanning terminal, so they are shown as recorded.
        $categories = array_map(fn ($k): array|string => match ((string) $k) {
            '__other' => SituationLabel::key('situationRoom.values.common.other'),
            '__none' => SituationLabel::key('situationRoom.values.common.not_recorded'),
            default => mb_substr((string) $k, 0, 80),
        }, array_keys($top));

        return $this->chart('cafeteria_refusals', 'hbar', self::SOURCE, $categories, [
            ['key' => 'scans', 'label' => SituationLabel::key('situationRoom.series.scans'), 'values' => array_values($top)],
        ]);
    }
}
