<?php

declare(strict_types=1);

namespace App\Services\Cafeteria;

use App\Enums\CafeteriaTransactionStatus;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaSubsidyLedger;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CafeteriaBillingService
{
    /**
     * Get billing totals for a provider over a period.
     *
     * @return array{provider_id: string, period_start: string, period_end: string, transaction_count: int, total_meal_amount: float, total_subsidy_covered: float, total_employee_payable: float, total_carry_forward_deductions: float, government_payable: float}
     */
    public function getProviderBilling(CafeteriaProvider $provider, Carbon $from, Carbon $to): array
    {
        $totals = $provider->transactions()
            ->selectRaw('
                COUNT(*) as transaction_count,
                SUM(meal_amount) as total_meal_amount,
                SUM(subsidy_amount_applied) as total_subsidy_covered,
                SUM(employee_payable_amount) as total_employee_payable,
                SUM(deduction_amount) as total_carry_forward_deductions
            ')
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
            ->where('status', CafeteriaTransactionStatus::Accepted)
            ->first();

        $totalMeal      = (float) ($totals->total_meal_amount ?? 0);
        $subsidyCovered = (float) ($totals->total_subsidy_covered ?? 0);
        $employeePayable = (float) ($totals->total_employee_payable ?? 0);

        return [
            'provider_id'                  => $provider->id,
            'period_start'                 => $from->toDateString(),
            'period_end'                   => $to->toDateString(),
            'transaction_count'            => (int) ($totals->transaction_count ?? 0),
            'total_meal_amount'            => $totalMeal,
            'total_subsidy_covered'        => $subsidyCovered,
            'total_employee_payable'       => $employeePayable,
            'total_carry_forward_deductions' => (float) ($totals->total_carry_forward_deductions ?? 0),
            'government_payable'           => $subsidyCovered,
        ];
    }

    /**
     * Get billing summaries for all active providers over a period.
     *
     * @return Collection<int, array>
     */
    public function getAllProvidersBilling(Carbon $from, Carbon $to, ?string $organizationId = null): Collection
    {
        $query = CafeteriaProvider::query()
            ->where('is_active', true)
            ->withCount([
                'transactions as transaction_count' => fn ($q) => $q
                    ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
                    ->where('status', CafeteriaTransactionStatus::Accepted),
            ])
            ->withSum(
                ['transactions as total_meal_amount' => fn ($q) => $q
                    ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
                    ->where('status', CafeteriaTransactionStatus::Accepted)],
                'meal_amount'
            )
            ->withSum(
                ['transactions as total_subsidy_covered' => fn ($q) => $q
                    ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
                    ->where('status', CafeteriaTransactionStatus::Accepted)],
                'subsidy_amount_applied'
            );

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        return $query->get()->map(fn ($provider) => [
            'provider_id'           => $provider->id,
            'provider_code'         => $provider->code,
            'provider_name_en'      => $provider->name_en,
            'provider_name_am'      => $provider->name_am,
            'transaction_count'     => (int) $provider->transaction_count,
            'total_meal_amount'     => (float) ($provider->total_meal_amount ?? 0),
            'total_subsidy_covered' => (float) ($provider->total_subsidy_covered ?? 0),
            'government_payable'    => (float) ($provider->total_subsidy_covered ?? 0),
        ]);
    }

    /**
     * Get payroll deduction summary per employee (carry-forward deductions to deduct from salary).
     *
     * @return Collection<int, array{employee_id: string, pending_deduction: float, ledger_balance: float}>
     */
    public function getPayrollDeductions(Carbon $payrollMonth, ?string $organizationId = null): Collection
    {
        $from = $payrollMonth->copy()->startOfMonth();
        $to   = $payrollMonth->copy()->endOfMonth();

        // Find employees with negative ledger balances (carry-forward deductions)
        $subQuery = CafeteriaSubsidyLedger::query()
            ->selectRaw('employee_id, MAX(created_at) as latest_at')
            ->groupBy('employee_id');

        $ledgerSnapshot = CafeteriaSubsidyLedger::query()
            ->joinSub($subQuery, 'latest', function ($join): void {
                $join->on('cafeteria_subsidy_ledger.employee_id', '=', 'latest.employee_id')
                    ->on('cafeteria_subsidy_ledger.created_at', '=', 'latest.latest_at');
            })
            ->where('cafeteria_subsidy_ledger.balance_after', '<', 0)
            ->with('employee.currentAssignment')
            ->get();

        return $ledgerSnapshot
            ->when(
                $organizationId !== null,
                fn (Collection $c) => $c->filter(
                    fn ($row) => $row->employee?->currentAssignment?->organization_id === $organizationId
                )
            )
            ->map(fn ($row) => [
                'employee_id'       => $row->employee_id,
                'pending_deduction' => abs((float) $row->balance_after),
                'ledger_balance'    => (float) $row->balance_after,
            ])
            ->values();
    }

    /**
     * Get deduction amount for a single employee.
     */
    public function getEmployeePendingDeduction(Employee $employee): float
    {
        $latest = CafeteriaSubsidyLedger::query()
            ->where('employee_id', $employee->id)
            ->orderByDesc('ledger_date')
            ->orderByDesc('created_at')
            ->value('balance_after');

        $balance = $latest !== null ? (float) $latest : 0.0;

        return $balance < 0 ? abs($balance) : 0.0;
    }
}
