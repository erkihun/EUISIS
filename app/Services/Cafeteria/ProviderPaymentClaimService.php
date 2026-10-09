<?php

declare(strict_types=1);

namespace App\Services\Cafeteria;

use App\Enums\CafeteriaTransactionStatus;
use App\Models\CafeteriaFoodOrder;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final readonly class ProviderPaymentClaimService
{
    /** @param Builder<CafeteriaTransaction> $query */
    public function summary(CafeteriaProvider $provider, Builder $query, array $filters): array
    {
        $transactions = (clone $query)->get([
            'id',
            'status',
            'subsidy_amount_applied',
            'employee_payable_amount',
            'consumed_days_count',
            'transaction_date',
        ]);

        $accepted = $transactions->filter(fn (CafeteriaTransaction $transaction): bool => $transaction->status === CafeteriaTransactionStatus::Accepted);
        $rejected = $transactions->filter(fn (CafeteriaTransaction $transaction): bool => $transaction->status === CafeteriaTransactionStatus::Rejected);
        $reversed = $transactions->filter(fn (CafeteriaTransaction $transaction): bool => $transaction->status === CafeteriaTransactionStatus::Reversed);

        $period = $this->period($filters);
        $totalSubsidyPayable = (float) $accepted->sum(fn (CafeteriaTransaction $transaction): float => (float) $transaction->subsidy_amount_applied);
        $reversalAmount = (float) $reversed->sum(fn (CafeteriaTransaction $transaction): float => (float) $transaction->subsidy_amount_applied);

        return [
            'provider_name' => $provider->name_en,
            'provider_code' => $provider->code,
            'assigned_institution' => $provider->organization?->name_en,
            'period_start' => $period['start'],
            'period_end' => $period['end'],
            'total_transactions' => $transactions->count(),
            'accepted_transactions' => $accepted->count(),
            'rejected_transactions' => $rejected->count(),
            'total_subsidy_payable' => round($totalSubsidyPayable, 2),
            'total_employee_payable' => round((float) $transactions->sum(fn (CafeteriaTransaction $transaction): float => (float) $transaction->employee_payable_amount), 2),
            'total_payable_to_provider' => round($totalSubsidyPayable, 2),
            'total_consumed_days' => (int) $accepted->sum(fn (CafeteriaTransaction $transaction): int => (int) $transaction->consumed_days_count),
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
