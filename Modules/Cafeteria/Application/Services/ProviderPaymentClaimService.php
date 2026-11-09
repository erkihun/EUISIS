<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Application\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Modules\Cafeteria\Domain\Enums\CafeteriaTransactionStatus;
use Modules\Cafeteria\Domain\Models\CafeteriaFoodOrder;
use Modules\Cafeteria\Domain\Models\CafeteriaProvider;
use Modules\Cafeteria\Domain\Models\CafeteriaTransaction;

final readonly class ProviderPaymentClaimService
{
    /** @param Builder<CafeteriaTransaction> $query */
    public function summary(CafeteriaProvider $provider, Builder $query, array $filters): array
    {
        // One aggregate query (risk register SCL-5): the period's
        // transactions are summed by the database, never loaded into PHP.
        $accepted = CafeteriaTransactionStatus::Accepted->value;
        $rejected = CafeteriaTransactionStatus::Rejected->value;
        $reversed = CafeteriaTransactionStatus::Reversed->value;

        $totals = (clone $query)->toBase()
            ->reorder()
            ->selectRaw('COUNT(*) AS total_transactions')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS accepted_transactions', [$accepted])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS rejected_transactions', [$rejected])
            ->selectRaw('SUM(CASE WHEN status = ? THEN COALESCE(subsidy_amount_applied, 0) ELSE 0 END) AS subsidy_payable', [$accepted])
            ->selectRaw('SUM(CASE WHEN status = ? THEN COALESCE(subsidy_amount_applied, 0) ELSE 0 END) AS reversal_amount', [$reversed])
            ->selectRaw('SUM(COALESCE(employee_payable_amount, 0)) AS employee_payable')
            ->selectRaw('SUM(CASE WHEN status = ? THEN COALESCE(consumed_days_count, 0) ELSE 0 END) AS consumed_days', [$accepted])
            ->first();

        $period = $this->period($filters);
        $totalSubsidyPayable = (float) ($totals->subsidy_payable ?? 0);
        $reversalAmount = (float) ($totals->reversal_amount ?? 0);

        return [
            'provider_name' => $provider->name_en,
            'provider_code' => $provider->code,
            'assigned_institution' => $provider->organization?->name_en,
            'period_start' => $period['start'],
            'period_end' => $period['end'],
            'total_transactions' => (int) ($totals->total_transactions ?? 0),
            'accepted_transactions' => (int) ($totals->accepted_transactions ?? 0),
            'rejected_transactions' => (int) ($totals->rejected_transactions ?? 0),
            'total_subsidy_payable' => round($totalSubsidyPayable, 2),
            'total_employee_payable' => round((float) ($totals->employee_payable ?? 0), 2),
            'total_payable_to_provider' => round($totalSubsidyPayable, 2),
            'total_consumed_days' => (int) ($totals->consumed_days ?? 0),
            'total_food_orders_served' => $this->servedOrdersCount($provider, $period['start'], $period['end']),
            'reversal_amount' => round($reversalAmount, 2),
            'net_payable_amount' => round($totalSubsidyPayable - $reversalAmount, 2),
        ];
    }

    /** @return array{start: string, end: string} */
    public function period(array $filters): array
    {
        $start = (string) ($filters['start_date'] ?? $filters['date'] ?? Carbon::now()->startOfMonth()->toDateString());
        $end = (string) ($filters['end_date'] ?? $filters['date'] ?? Carbon::now()->toDateString());

        return ['start' => $start, 'end' => $end];
    }

    private function servedOrdersCount(CafeteriaProvider $provider, string $startDate, string $endDate): int
    {
        return CafeteriaFoodOrder::query()
            ->where('cafeteria_provider_id', $provider->id)
            ->where('status', 'served')
            ->whereBetween('order_date', [$startDate, $endDate])
            ->count();
    }
}
