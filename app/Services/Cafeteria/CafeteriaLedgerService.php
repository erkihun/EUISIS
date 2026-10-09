<?php

declare(strict_types=1);

namespace App\Services\Cafeteria;

use App\Enums\CafeteriaLedgerEntryType;
use App\Enums\CafeteriaUsageMode;
use App\Models\CafeteriaSubsidyLedger;
use App\Models\CafeteriaTransaction;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Carbon;

class CafeteriaLedgerService
{
    /**
     * Get the current running balance for an employee.
     * Positive = credit; negative = pending deduction.
     */
    public function getBalance(Employee $employee): float
    {
        $latest = CafeteriaSubsidyLedger::query()
            ->where('employee_id', $employee->id)
            ->orderByDesc('ledger_date')
            ->orderByDesc('created_at')
            ->first('balance_after');

        return $latest ? (float) $latest->balance_after : 0.0;
    }

    public function getPendingDeduction(Employee $employee): float
    {
        $balance = $this->getBalance($employee);

        return $balance < 0 ? abs($balance) : 0.0;
    }

    public function record(
        Employee $employee,
        CafeteriaLedgerEntryType $entryType,
        float $amount,
        Carbon $date,
        bool $isWorkingDay,
        ?CafeteriaTransaction $transaction = null,
        ?string $description = null,
        ?User $actor = null,
        ?Carbon $allocatedForDate = null,
        ?Carbon $weekStart = null,
        ?Carbon $weekEnd = null,
        ?CafeteriaUsageMode $usageMode = null,
    ): CafeteriaSubsidyLedger {
        $previousBalance = $this->getBalance($employee);
        $newBalance      = $previousBalance + $amount;

        return CafeteriaSubsidyLedger::query()->create([
            'employee_id'              => $employee->id,
            'cafeteria_transaction_id' => $transaction?->id,
            'ledger_date'              => $date->toDateString(),
            'allocated_for_date'       => $allocatedForDate?->toDateString(),
            'week_start_date'          => $weekStart?->toDateString(),
            'week_end_date'            => $weekEnd?->toDateString(),
            'usage_mode'               => $usageMode?->value,
            'entry_type'               => $entryType,
            'amount'                   => $amount,
            'balance_after'            => $newBalance,
            'working_day'              => $isWorkingDay,
            'description'              => $description,
            'created_by'               => $actor?->id,
        ]);
    }

    /**
     * Record weekly forward subsidy usage.
     *
     * Creates one ledger entry per consumed working day, each stamped with
     * allocated_for_date so the availability service can detect them.
     *
     * @param  list<string>  $consumedDates  ISO date strings for consumed days
     */
    public function recordWeeklyUsage(
        Employee $employee,
        float $dailyAmount,
        array $consumedDates,
        Carbon $scanDate,
        CafeteriaTransaction $transaction,
        Carbon $weekStart,
        Carbon $weekEnd,
        CafeteriaUsageMode $usageMode,
        ?User $actor = null,
    ): void {
        foreach ($consumedDates as $dateStr) {
            $allocatedFor = Carbon::parse($dateStr);
            $this->record(
                $employee,
                CafeteriaLedgerEntryType::Usage,
                -$dailyAmount,
                $scanDate,
                true,
                $transaction,
                __('cafeteria.ledgerWeeklyUsageDesc', ['date' => $dateStr]),
                $actor,
                $allocatedFor,
                $weekStart,
                $weekEnd,
                $usageMode,
            );
        }
    }

    /**
     * Record employee-payable excess (no subsidy applied for this portion).
     */
    public function recordEmployeePayable(
        Employee $employee,
        float $amount,
        Carbon $date,
        CafeteriaTransaction $transaction,
        ?User $actor = null,
    ): CafeteriaSubsidyLedger {
        return $this->record(
            $employee,
            CafeteriaLedgerEntryType::ExtraUsage,
            0.0, // does not affect subsidy balance
            $date,
            false,
            $transaction,
            __('cafeteria.ledgerEmployeePayableDesc'),
            $actor,
        );
    }

    public function recordReversal(
        Employee $employee,
        float $reversalAmount,
        Carbon $date,
        CafeteriaTransaction $transaction,
        ?User $actor = null,
    ): CafeteriaSubsidyLedger {
        return $this->record(
            $employee,
            CafeteriaLedgerEntryType::Reversal,
            $reversalAmount,
            $date,
            true,
            $transaction,
            __('cafeteria.ledgerReversalDesc'),
            $actor,
        );
    }

    // ── Legacy helpers kept for compatibility ────────────────────────────────

    public function recordAllocation(
        Employee $employee,
        float $subsidyAmount,
        Carbon $date,
        CafeteriaTransaction $transaction,
        ?User $actor = null,
    ): CafeteriaSubsidyLedger {
        return $this->record(
            $employee,
            CafeteriaLedgerEntryType::Allocation,
            $subsidyAmount,
            $date,
            true,
            $transaction,
            __('cafeteria.ledgerAllocationDesc'),
            $actor,
        );
    }

    public function recordUsage(
        Employee $employee,
        float $subsidyUsed,
        Carbon $date,
        CafeteriaTransaction $transaction,
        ?User $actor = null,
    ): CafeteriaSubsidyLedger {
        return $this->record(
            $employee,
            CafeteriaLedgerEntryType::Usage,
            -$subsidyUsed,
            $date,
            true,
            $transaction,
            __('cafeteria.ledgerUsageDesc'),
            $actor,
        );
    }

    public function recordExtraUsage(
        Employee $employee,
        float $deductionAmount,
        Carbon $date,
        CafeteriaTransaction $transaction,
        ?User $actor = null,
    ): CafeteriaSubsidyLedger {
        return $this->record(
            $employee,
            CafeteriaLedgerEntryType::CarryForwardDeduction,
            -$deductionAmount,
            $date,
            true,
            $transaction,
            __('cafeteria.ledgerCarryForwardDesc'),
            $actor,
        );
    }
}
