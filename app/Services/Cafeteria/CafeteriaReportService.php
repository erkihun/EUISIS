<?php

declare(strict_types=1);

namespace App\Services\Cafeteria;

use App\Enums\CafeteriaReportType;
use App\Enums\CafeteriaTransactionStatus;
use App\Models\CafeteriaReportRun;
use App\Models\CafeteriaTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CafeteriaReportService
{
    public function __construct(private readonly CafeteriaProviderAccessService $providerAccess) {}

    /**
     * Get daily transaction summary grouped by provider.
     *
     * @return Collection<int, array{provider_id: string, provider_name: string, transaction_count: int, total_meal_amount: float, total_subsidy: float, total_employee_payable: float, total_deductions: float}>
     */
    public function getDailyProviderSummary(Carbon $date, ?User $user = null): Collection
    {
        $query = CafeteriaTransaction::query()
            ->selectRaw('
                cafeteria_provider_id,
                COUNT(*) as transaction_count,
                SUM(meal_amount) as total_meal_amount,
                SUM(subsidy_amount_applied) as total_subsidy,
                SUM(employee_payable_amount) as total_employee_payable,
                SUM(deduction_amount) as total_deductions
            ')
            ->with('provider')
            ->where('transaction_date', $date->toDateString())
            ->where('status', CafeteriaTransactionStatus::Accepted);

        if ($user !== null) {
            $this->providerAccess->filterProviderScopedQuery($user, $query);
        }

        return $query
            ->groupBy('cafeteria_provider_id')
            ->get()
            ->map(fn ($row) => [
                'provider_id' => $row->cafeteria_provider_id,
                'provider_name' => $row->provider?->name_en ?? '',
                'transaction_count' => (int) $row->transaction_count,
                'total_meal_amount' => (float) $row->total_meal_amount,
                'total_subsidy' => (float) $row->total_subsidy,
                'total_employee_payable' => (float) $row->total_employee_payable,
                'total_deductions' => (float) $row->total_deductions,
            ]);
    }

    /**
     * Get period transaction summary for report generation.
     *
     * @return array{period_start: string, period_end: string, total_transactions: int, total_extra_scans: int, total_meal_amount: float, total_subsidy: float, total_employee_payable: float, total_deductions: float, by_provider: Collection}
     */
    public function getPeriodSummary(Carbon $from, Carbon $to, ?string $organizationId = null, ?User $user = null): array
    {
        $query = CafeteriaTransaction::query()
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
            ->where('status', CafeteriaTransactionStatus::Accepted);

        if ($organizationId) {
            $query->whereHas(
                'employee.currentAssignment',
                fn ($q) => $q->where('organization_id', $organizationId)
            );
        }

        if ($user !== null) {
            $this->providerAccess->filterProviderScopedQuery($user, $query);
        }

        $totals = (clone $query)
            ->selectRaw('
                COUNT(*) as total_transactions,
                SUM(CASE WHEN is_extra_scan THEN 1 ELSE 0 END) as total_extra_scans,
                SUM(meal_amount) as total_meal_amount,
                SUM(subsidy_amount_applied) as total_subsidy,
                SUM(employee_payable_amount) as total_employee_payable,
                SUM(deduction_amount) as total_deductions
            ')
            ->first();

        $byProvider = (clone $query)
            ->selectRaw('
                cafeteria_provider_id,
                COUNT(*) as transaction_count,
                SUM(meal_amount) as total_meal_amount,
                SUM(subsidy_amount_applied) as total_subsidy,
                SUM(deduction_amount) as total_deductions
            ')
            ->with('provider:id,name_en,name_am,code')
            ->groupBy('cafeteria_provider_id')
            ->get()
            ->map(fn ($row) => [
                'provider_id' => $row->cafeteria_provider_id,
                'provider_name_en' => $row->provider?->name_en ?? '',
                'provider_name_am' => $row->provider?->name_am ?? '',
                'provider_code' => $row->provider?->code ?? '',
                'transaction_count' => (int) $row->transaction_count,
                'total_meal_amount' => (float) $row->total_meal_amount,
                'total_subsidy' => (float) $row->total_subsidy,
                'total_deductions' => (float) $row->total_deductions,
            ]);

        return [
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'total_transactions' => (int) ($totals->total_transactions ?? 0),
            'total_extra_scans' => (int) ($totals->total_extra_scans ?? 0),
            'total_meal_amount' => (float) ($totals->total_meal_amount ?? 0),
            'total_subsidy' => (float) ($totals->total_subsidy ?? 0),
            'total_employee_payable' => (float) ($totals->total_employee_payable ?? 0),
            'total_deductions' => (float) ($totals->total_deductions ?? 0),
            'by_provider' => $byProvider,
        ];
    }

    /**
     * Get per-employee transaction detail for a period (for payroll integration).
     *
     * @return Collection<int, array{employee_id: string, transaction_count: int, total_subsidy: float, total_deductions: float, net_payroll_deduction: float}>
     */
    public function getEmployeePayrollSummary(Carbon $from, Carbon $to, ?string $organizationId = null): Collection
    {
        $query = CafeteriaTransaction::query()
            ->selectRaw('
                employee_id,
                COUNT(*) as transaction_count,
                SUM(subsidy_amount_applied) as total_subsidy,
                SUM(deduction_amount) as total_deductions
            ')
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
            ->where('status', CafeteriaTransactionStatus::Accepted)
            ->groupBy('employee_id');

        if ($organizationId) {
            $query->whereHas(
                'employee.currentAssignment',
                fn ($q) => $q->where('organization_id', $organizationId)
            );
        }

        return $query->get()->map(fn ($row) => [
            'employee_id' => $row->employee_id,
            'transaction_count' => (int) $row->transaction_count,
            'total_subsidy' => (float) $row->total_subsidy,
            'total_deductions' => (float) $row->total_deductions,
            'net_payroll_deduction' => max(0.0, (float) $row->total_deductions - (float) $row->total_subsidy),
        ]);
    }

    /**
     * Create a new report run record with computed totals.
     */
    public function createReportRun(
        CafeteriaReportType $reportType,
        Carbon $from,
        Carbon $to,
        User $generatedBy,
        ?string $organizationId = null,
    ): CafeteriaReportRun {
        $summary = $this->getPeriodSummary($from, $to, $organizationId, $generatedBy);
        $providerIds = $this->providerAccess->accessibleProviderIds($generatedBy);

        return CafeteriaReportRun::query()->create([
            'report_number' => $this->generateReportNumber($reportType),
            'report_type' => $reportType,
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'status' => 'completed',
            'generated_by' => $generatedBy->id,
            'generated_at' => now(),
            'organization_id' => $organizationId,
            'filters' => [
                'organization_id' => $organizationId,
                'provider_ids' => $providerIds === [] ? null : $providerIds,
            ],
            'totals' => [
                'total_transactions' => $summary['total_transactions'],
                'total_extra_scans' => $summary['total_extra_scans'],
                'total_meal_amount' => $summary['total_meal_amount'],
                'total_subsidy' => $summary['total_subsidy'],
                'total_employee_payable' => $summary['total_employee_payable'],
                'total_deductions' => $summary['total_deductions'],
            ],
        ]);
    }

    private function generateReportNumber(CafeteriaReportType $type): string
    {
        $prefix = match ($type) {
            CafeteriaReportType::Daily => 'RPT-D',
            CafeteriaReportType::Weekly => 'RPT-W',
            CafeteriaReportType::Monthly => 'RPT-M',
        };

        $existing = CafeteriaReportRun::query()
            ->where('report_type', $type)
            ->whereYear('created_at', now()->year)
            ->count();

        return $prefix.'-'.now()->format('Y').'-'.str_pad((string) ($existing + 1), 4, '0', STR_PAD_LEFT);
    }
}
