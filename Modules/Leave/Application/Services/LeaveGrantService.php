<?php

declare(strict_types=1);

namespace Modules\Leave\Application\Services;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Modules\Leave\Domain\Enums\LeaveCategory;
use Modules\Leave\Domain\Enums\LeaveEntryType;
use Modules\Leave\Domain\Models\LeaveRule;
use Modules\Leave\Domain\Models\LeaveType;

/**
 * Writes what the rules grant an employee for a budget year, and closes the
 * days that can no longer be used (ADR 0039, decision 6).
 *
 * Idempotent: each grant and each expiry carries a unique key, so the yearly
 * command, a page that opens a balance and a request that checks one may all
 * run it. Nothing is granted without a rule in force, and annual leave is
 * not granted to an employee whose service start date is unknown.
 */
class LeaveGrantService
{
    public function __construct(
        private readonly LeaveCalendar $calendar,
        private readonly LeaveRules $rules,
        private readonly LeaveLedger $ledger,
    ) {}

    /** Bring one employee's ledger up to date for the budget year of $onDate (today by default). */
    public function settle(Employee $employee, ?string $onDate = null): void
    {
        $onDate ??= $this->calendar->today();
        $budgetYear = $this->calendar->budgetYearOf($onDate);

        // One writer per employee: the row lock keeps two pages from closing the same old days twice.
        DB::transaction(function () use ($employee, $onDate, $budgetYear): void {
            Employee::query()->whereKey($employee->getKey())->lockForUpdate()->first();
            foreach (LeaveType::query()->active()->where('uses_balance', true)->get() as $type) {
                $rule = $this->rules->ruleFor($type, $onDate);
                if ($rule === null) {
                    continue;
                }
                $this->grant($employee, $type, $rule, $budgetYear, $onDate);
                $this->expire($employee, $type, $rule, $budgetYear);
            }
        });
    }

    /**
     * The days the rule grants for a budget year, as of $onDate.
     *
     * @return array{days: float, prior_days: float, reason: ?string}
     *                                                                reason: null when granted, else why nothing is granted yet
     */
    public function entitlement(Employee $employee, LeaveType $type, LeaveRule $rule, int $budgetYear, string $onDate): array
    {
        $nothing = static fn (string $reason): array => ['days' => 0.0, 'prior_days' => 0.0, 'reason' => $reason];
        $base = (float) ($rule->base_days ?? 0);
        if ($base <= 0) {
            return $nothing('no_figure');
        }

        $start = $employee->service_start_date?->toDateString();
        $yearStart = $this->calendar->budgetYearStart($budgetYear);
        $yearEnd = $this->calendar->budgetYearEnd($budgetYear);

        if ($type->category !== LeaveCategory::Annual) {
            // A flat yearly allowance (the seven days for personal matters): whole, once the waiting period is over.
            if ($rule->waiting_months > 0) {
                if ($start === null) {
                    return $nothing('no_service_start_date');
                }
                if ($this->calendar->addMonths($start, $rule->waiting_months) > $onDate) {
                    return $nothing('waiting_period');
                }
            }

            return ['days' => $base, 'prior_days' => 0.0, 'reason' => null];
        }

        if ($start === null) {
            return $nothing('no_service_start_date');
        }
        if ($start > $yearEnd) {
            return $nothing('not_in_service');
        }
        $eligibleFrom = $this->calendar->addMonths($start, $rule->waiting_months);
        if ($eligibleFrom > $onDate) {
            return $nothing('waiting_period');
        }

        $increment = (float) ($rule->increment_per_service_year ?? 0);
        $full = $base + $increment * $this->calendar->wholeYearsBetween($start, $yearStart);
        if ($rule->max_days !== null) {
            $full = min($full, (float) $rule->max_days);
        }

        if ($start > $yearStart) {
            // Joined during this budget year: in proportion to the months served in it.
            $months = min(12, $this->calendar->wholeMonthsBetween($start, $this->calendar->addDays($yearEnd, 1)));

            return ['days' => $this->toHalfDays($full * $months / 12), 'prior_days' => 0.0, 'reason' => null];
        }

        // The waiting period ended in this budget year: the service of the year before is granted now, in proportion.
        $prior = 0.0;
        if ($eligibleFrom >= $yearStart && $start < $yearStart) {
            $prior = $this->toHalfDays($base * min(12, $this->calendar->wholeMonthsBetween($start, $yearStart)) / 12);
        }

        return ['days' => $this->toHalfDays($full), 'prior_days' => $prior, 'reason' => null];
    }

    private function grant(Employee $employee, LeaveType $type, LeaveRule $rule, int $budgetYear, string $onDate): void
    {
        $entitlement = $this->entitlement($employee, $type, $rule, $budgetYear, $onDate);
        if ($entitlement['reason'] !== null) {
            return;
        }
        $common = ['leave_rule_set_id' => $rule->leave_rule_set_id];
        if ($entitlement['days'] > 0) {
            $this->ledger->write($employee->id, $type->id, $budgetYear, LeaveEntryType::Grant, $entitlement['days'], [
                ...$common, 'unique_key' => "grant:{$employee->id}:{$type->id}:{$budgetYear}",
            ]);
        }
        if ($entitlement['prior_days'] > 0) {
            $this->ledger->write($employee->id, $type->id, $budgetYear, LeaveEntryType::Grant, $entitlement['prior_days'], [
                ...$common, 'unique_key' => "grant-prior:{$employee->id}:{$type->id}:{$budgetYear}", 'note' => 'Service before the waiting period ended, in proportion.',
            ]);
        }
    }

    /** Close every bucket whose last usable year is behind us and that still holds days. */
    private function expire(Employee $employee, LeaveType $type, LeaveRule $rule, int $budgetYear): void
    {
        foreach ($this->ledger->buckets($employee->id, $type->id) as $bucket) {
            if ($bucket['balance'] <= 0 || $this->rules->lastChanceYear($rule, $bucket['year'], $bucket['postponed_until']) >= $budgetYear) {
                continue;
            }
            $this->ledger->write($employee->id, $type->id, $bucket['year'], LeaveEntryType::Expired, -$bucket['balance'], [
                'leave_rule_set_id' => $rule->leave_rule_set_id,
            ]);
        }
    }

    /** Leave is counted in half days at the smallest. */
    private function toHalfDays(float $days): float
    {
        return round($days * 2) / 2;
    }
}
