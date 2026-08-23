<?php

declare(strict_types=1);

namespace CafeteriaSystem\Services;

use CafeteriaSystem\Models\CafeteriaServiceTransaction;
use CafeteriaSystem\Models\CafeteriaSettlement;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Daily / weekly / monthly reporting and settlement generation.
 *
 * Everything is computed from locally recorded transactions — the cafeteria
 * never calls EUISIS to build a report, so reporting keeps working even when
 * the integration is down.
 */
readonly class CafeteriaReportService
{
    /**
     * Served-meal totals bucketed by day, week or month.
     *
     * @return array<int, array{period: string, transactions: int, total_amount: float, total_subsidy: float}>
     */
    public function summary(
        string $providerId,
        CarbonInterface $from,
        CarbonInterface $to,
        string $granularity = 'day',
    ): array {
        $periodExpression = $this->periodExpression($granularity);

        return CafeteriaServiceTransaction::query()
            ->where('provider_id', $providerId)
            ->where('status', 'served')
            ->whereBetween('served_at', [$from, $to])
            ->groupBy(DB::raw($periodExpression))
            ->orderBy(DB::raw($periodExpression))
            ->get([
                DB::raw("{$periodExpression} as period"),
                DB::raw('count(*) as transactions'),
                DB::raw('coalesce(sum(meal_amount), 0) as total_amount'),
                DB::raw('coalesce(sum(subsidy_amount), 0) as total_subsidy'),
            ])
            ->map(fn ($row): array => [
                'period' => (string) $row->period,
                'transactions' => (int) $row->transactions,
                'total_amount' => (float) $row->total_amount,
                'total_subsidy' => (float) $row->total_subsidy,
            ])
            ->all();
    }

    /**
     * Period-bucketing SQL for the active driver.
     *
     * PostgreSQL uses to_char(); SQLite (used by the test suite and small
     * single-site deployments) has no such function and needs strftime().
     * Keeping both here means reports behave identically on either.
     */
    private function periodExpression(string $granularity): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => match ($granularity) {
                'month' => "strftime('%Y-%m', served_at)",
                'week' => "strftime('%Y-W%W', served_at)",
                default => "strftime('%Y-%m-%d', served_at)",
            },
            // MySQL/MariaDB have no to_char(); DATE_FORMAT is the equivalent.
            'mysql', 'mariadb' => match ($granularity) {
                'month' => "DATE_FORMAT(served_at, '%Y-%m')",
                'week' => "DATE_FORMAT(served_at, '%x-W%v')",
                default => "DATE_FORMAT(served_at, '%Y-%m-%d')",
            },
            'sqlsrv' => match ($granularity) {
                'month' => "FORMAT(served_at, 'yyyy-MM')",
                'week' => "CONCAT(YEAR(served_at), '-W', DATEPART(ISO_WEEK, served_at))",
                default => "FORMAT(served_at, 'yyyy-MM-dd')",
            },
            // PostgreSQL and anything else that speaks to_char().
            default => match ($granularity) {
                'month' => "to_char(served_at, 'YYYY-MM')",
                'week' => "to_char(served_at, 'IYYY-\"W\"IW')",
                default => "to_char(served_at, 'YYYY-MM-DD')",
            },
        };
    }

    /**
     * Freeze a settlement claim for a period. Mirrors the reference module's
     * provider payment claim: accepted transactions only.
     */
    public function generateSettlement(string $providerId, CarbonInterface $from, CarbonInterface $to): CafeteriaSettlement
    {
        $totals = CafeteriaServiceTransaction::query()
            ->where('provider_id', $providerId)
            ->where('status', 'served')
            ->whereBetween('served_at', [$from, $to])
            ->selectRaw('count(*) as transaction_count')
            ->selectRaw('coalesce(sum(meal_amount), 0) as total_amount')
            ->selectRaw('coalesce(sum(subsidy_amount), 0) as total_subsidy')
            ->first();

        return CafeteriaSettlement::query()->create([
            'provider_id' => $providerId,
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'transaction_count' => (int) ($totals->transaction_count ?? 0),
            'total_amount' => (float) ($totals->total_amount ?? 0),
            'total_subsidy' => (float) ($totals->total_subsidy ?? 0),
            'status' => 'generated',
            'generated_at' => now(),
        ]);
    }

    /**
     * Export rows for CSV/settlement download. Carries the verified snapshot
     * only — no field beyond what was already stored at serve time.
     *
     * @return array<int, array<string, mixed>>
     */
    public function exportRows(string $providerId, CarbonInterface $from, CarbonInterface $to): array
    {
        return CafeteriaServiceTransaction::query()
            ->where('provider_id', $providerId)
            ->whereBetween('served_at', [$from, $to])
            ->orderBy('served_at')
            ->get([
                'transaction_number', 'employee_number', 'employee_name',
                'organization_name', 'card_status', 'status',
                'meal_amount', 'subsidy_amount', 'employee_payable', 'served_at',
            ])
            ->map(fn (CafeteriaServiceTransaction $transaction): array => [
                'transaction_number' => $transaction->transaction_number,
                'employee_number' => $transaction->employee_number,
                'employee_name' => $transaction->employee_name,
                'organization_name' => $transaction->organization_name,
                'card_status' => $transaction->card_status,
                'status' => $transaction->status,
                'meal_amount' => (float) $transaction->meal_amount,
                'subsidy_amount' => (float) $transaction->subsidy_amount,
                'employee_payable' => (float) $transaction->employee_payable,
                'served_at' => $transaction->served_at?->toDateTimeString(),
            ])
            ->all();
    }
}
