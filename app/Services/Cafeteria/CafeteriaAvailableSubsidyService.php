<?php

declare(strict_types=1);

namespace App\Services\Cafeteria;

use App\Enums\CafeteriaLedgerEntryType;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaSubsidyLedger;
use App\Models\CafeteriaSubsidyRule;
use App\Models\CafeteriaTransactionConsumedDay;
use App\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * Calculates how much cafeteria subsidy is available for an employee
 * from a given scan date through Friday of the same week.
 *
 * Rules enforced:
 *  - Only Mon–Fri is counted (weekends excluded).
 *  - Public holidays are excluded.
 *  - Past days (before today) are not claimable.
 *  - Future-week borrowing is not allowed.
 *  - Already-consumed subsidy for dates in the remaining window is deducted.
 */
class CafeteriaAvailableSubsidyService
{
    public function __construct(private readonly CafeteriaWeekWindowService $weekWindow) {}

    /**
     * @return array{
     *   daily_amount: float,
     *   available_days: list<string>,
     *   available_days_count: int,
     *   gross_available: float,
     *   already_consumed: float,
     *   remaining: float,
     *   week_start: Carbon,
     *   week_end: Carbon,
     * }
     */
    public function calculate(Employee $employee, Carbon $scanDate, CafeteriaSubsidyRule $rule, ?CafeteriaProvider $provider = null): array
    {
        $dailyAmount = (float) $rule->subsidy_amount;
        $weekStart = $this->weekWindow->weekStart($scanDate);
        $weekEnd = $this->weekWindow->weekEnd($scanDate);
        $availableDays = $this->weekWindow->remainingWorkingDaysFrom($scanDate, $provider);

        if ($availableDays !== []) {
            $consumedDates = CafeteriaTransactionConsumedDay::query()
                ->where('employee_id', $employee->id)
                ->whereNull('reversed_at')
                ->whereBetween('consumed_date', [min($availableDays), max($availableDays).' 23:59:59'])
                ->pluck('consumed_date')
                ->map(fn ($date) => Carbon::parse($date)->toDateString())
                ->all();

            $availableDays = array_values(array_diff($availableDays, $consumedDates));
        }

        $dayCount = count($availableDays);
        $grossAvailable = round($dailyAmount * $dayCount, 2);

        // Sum usage/deduction ledger entries where allocated_for_date falls
        // in the remaining window (today..Friday).  We only care about amounts
        // that reduce the available balance for those specific future dates.
        $alreadyConsumed = 0.0;

        if ($dayCount > 0) {
            $consumed = CafeteriaSubsidyLedger::query()
                ->where('employee_id', $employee->id)
                ->whereIn('entry_type', [
                    CafeteriaLedgerEntryType::Usage->value,
                    CafeteriaLedgerEntryType::CarryForwardDeduction->value,
                ])
                ->whereNotNull('allocated_for_date')
                ->where(function ($query) use ($availableDays): void {
                    foreach ($availableDays as $day) {
                        $query->orWhereDate('allocated_for_date', $day);
                    }
                })
                ->sum('amount');

            // amounts are stored negative for debits; abs gives consumed total
            $alreadyConsumed = round(abs((float) $consumed), 2);
        }

        $remaining = max(0.0, round($grossAvailable - $alreadyConsumed, 2));

        return [
            'daily_amount' => $dailyAmount,
            'available_days' => $availableDays,
            'available_days_count' => $dayCount,
            'gross_available' => $grossAvailable,
            'already_consumed' => $alreadyConsumed,
            'remaining' => $remaining,
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
        ];
    }

    /**
     * How many days must be consumed to cover $amount, counting sequentially
     * from the first element of $availableDays up to $dailyAmount per day.
     * Returns the list of dates that will be consumed and the total subsidy covered.
     *
     * @param  list<string>  $availableDays
     * @return array{dates: list<string>, subsidy_covered: float}
     */
    public function daysNeededForAmount(array $availableDays, float $dailyAmount, float $amount): array
    {
        $remaining = $amount;
        $dates = [];

        foreach ($availableDays as $day) {
            if ($remaining <= 0) {
                break;
            }
            $dates[] = $day;
            $remaining = round($remaining - $dailyAmount, 2);
        }

        $subsidyCovered = round(min($amount, count($dates) * $dailyAmount), 2);

        return ['dates' => $dates, 'subsidy_covered' => $subsidyCovered];
    }
}
