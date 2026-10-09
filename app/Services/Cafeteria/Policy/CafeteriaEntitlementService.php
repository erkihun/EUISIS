<?php

declare(strict_types=1);

namespace App\Services\Cafeteria\Policy;

use App\Enums\CafeteriaExtraScanPolicy;
use App\Enums\CafeteriaOperationalStatus;
use App\Enums\CafeteriaTransactionStatus;
use App\Enums\CafeteriaUsageMode;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaServicePolicy;
use App\Models\CafeteriaTransaction;
use App\Models\CafeteriaTransactionConsumedDay;
use App\Models\Employee;
use App\Models\EmployeeCafeteriaExclusion;
use App\Services\Cafeteria\CafeteriaSettingsService;
use App\Services\Cafeteria\CafeteriaWeekWindowService;
use App\Services\Cafeteria\WorkingDayCalendarService;
use Illuminate\Support\Carbon;

/**
 * Decides what one scan may consume (docs/cafeteria-entitlement-rules.md).
 *
 * Two independent gates, both must pass:
 *   1. the CAFETERIA can serve now — operational status, opening hours and
 *      its physical calendar (special days, system day rules, weekend and
 *      holiday modes);
 *   2. the EMPLOYEE is entitled — the employee organization's policy: its
 *      working days, holiday and leave rules, advance usage, daily uses and
 *      extra-scan policy.
 *
 * Consumption is counted per employee + date across EVERY cafeteria, never
 * per location: an entitlement used at a branch is gone at the main
 * cafeteria too. The database enforces the same boundary (active_key).
 */
class CafeteriaEntitlementService
{
    public function __construct(
        private readonly CafeteriaPolicyResolver $resolver,
        private readonly CafeteriaTransactionPricingService $pricing,
        private readonly WorkingDayCalendarService $calendar,
        private readonly CafeteriaWeekWindowService $weekWindow,
        private readonly CafeteriaSettingsService $settings,
    ) {}

    /**
     * Read-only eligibility summary: who owns the entitlement, where service
     * would happen, who is paid, and what it would cost.
     *
     * @return array<string, mixed>
     */
    public function check(Employee $employee, CafeteriaProvider $cafeteria, Carbon $at, CafeteriaUsageMode $mode = CafeteriaUsageMode::SingleDay): array
    {
        $resolution = $this->resolver->resolve($employee, $cafeteria, $at);
        $decision = $resolution->resolved() ? $this->evaluate($employee, $cafeteria, $at, $resolution, $mode) : null;
        $pricing = $decision?->eligible ? $this->pricing->price($resolution->policy, count($decision->entitlements), $decision->isEmployeePaid()) : null;

        return [
            'eligible' => $decision?->eligible ?? false,
            'reason' => $resolution->reason ?? $decision?->reason,
            'employee_organization_id' => $resolution->employeeOrganizationId(),
            'cafeteria_service_network_id' => $resolution->network?->id,
            'cafeteria_id' => $cafeteria->id,
            'provider_id' => $resolution->provider?->id,
            'cafeteria_service_policy_id' => $resolution->policy?->id,
            'policy_version' => $resolution->policy?->version_no,
            'available_entitlement_dates' => $decision?->availableDates ?? [],
            'consumed_entitlement_dates' => $decision?->consumedDates() ?? [],
            'financial_breakdown' => $pricing === null ? null : [
                'currency' => $pricing->currency,
                'subsidy' => $pricing->subsidy(),
                'employee_contribution' => $pricing->employeeAmount(),
                'provider_price' => $pricing->unitPrice(),
                'total' => $pricing->total(),
                'employee_paid' => $pricing->employeePaid,
            ],
        ];
    }

    public function evaluate(
        Employee $employee,
        CafeteriaProvider $cafeteria,
        Carbon $at,
        CafeteriaPolicyResolution $resolution,
        CafeteriaUsageMode $mode,
    ): CafeteriaEntitlementDecision {
        /** @var CafeteriaServicePolicy $policy */
        $policy = $resolution->policy;
        $today = $at->copy()->startOfDay();
        $todayString = $today->toDateString();
        $weekStart = $this->weekWindow->weekStart($today);
        $weekEnd = $this->weekWindow->weekEnd($today);
        $deny = fn (string $reason, ?string $message = null) => CafeteriaEntitlementDecision::deny($reason, $message, $weekStart, $weekEnd);

        // ── Gate 1: the cafeteria can serve ─────────────────────────────────
        if (! $cafeteria->isServing()) {
            return $deny($cafeteria->operational_status === CafeteriaOperationalStatus::TemporarilyClosed ? 'cafeteria_temporarily_closed' : 'cafeteria_closed');
        }
        if (! $this->withinOpeningHours($cafeteria, $at)) {
            return $deny('cafeteria_outside_opening_hours');
        }
        if (! $this->calendar->isCafeteriaOpen($today, $cafeteria)) {
            return $deny($today->isWeekend() ? 'cafeteria_closed_weekend' : 'cafeteria_closed');
        }

        $isHoliday = $this->calendar->isHoliday($today);
        $sequence = $this->acceptedScansOn($employee, $todayString) + 1;
        $isExtra = $sequence > 1;
        $paid = fn (): CafeteriaEntitlementDecision => new CafeteriaEntitlementDecision(
            true, null, null, CafeteriaEntitlementDecision::MODE_EMPLOYEE_PAID, [], [],
            false, $isHoliday, $isExtra, $sequence, $weekStart, $weekEnd,
        );

        // ── Gate 2: the employee organization's entitlement ────────────────
        $notEntitled = $this->nonEntitlementReason($policy, $today, $cafeteria);
        if ($notEntitled !== null) {
            return $this->paidServiceAllowed($notEntitled, $today) ? $paid() : $deny($notEntitled);
        }

        if ($policy->block_employee_leave && $this->isOnLeave($employee, $today)) {
            return $this->settings->get('leave_scan_mode', 'reject') === 'employee_payable' ? $paid() : $deny('employee_on_leave');
        }

        if ($mode === CafeteriaUsageMode::UseRemainingWeek && ! $policy->allow_advance_usage) {
            return $deny('upfront_usage_disabled', __('cafeteria.upfrontUsageDisabled'));
        }

        // Entitlement dates: today, then the rest of the current window only
        // (past days are never claimable; the next window is never borrowed).
        $future = [];
        for ($day = $today->copy()->addDay(); $day->lte($weekEnd); $day->addDay()) {
            if ($this->nonEntitlementReason($policy, $day, $cafeteria) === null) {
                $future[] = $day->toDateString();
            }
        }
        if ($policy->block_employee_leave && $this->settings->getBool('exclude_leave_days_from_subsidy')) {
            $future = $this->withoutLeaveDays($employee, $future);
        }

        $maxUses = max(1, (int) $policy->max_daily_uses);
        $used = $this->usedSlots($employee, [$todayString, ...$future]);
        $todayFree = ($used[$todayString] ?? 0) < $maxUses;
        $futureFree = array_values(array_filter($future, fn (string $date): bool => ($used[$date] ?? 0) < $maxUses));
        if ($policy->advance_max_days !== null) {
            $futureFree = array_slice($futureFree, 0, max(0, (int) $policy->advance_max_days));
        }
        $available = $todayFree ? [$todayString, ...$futureFree] : $futureFree;

        $claim = fn (string $date, string $usageType): array => ['date' => $date, 'slot' => ($used[$date] ?? 0) + 1, 'usage_type' => $usageType];
        $entitled = fn (array $claims): CafeteriaEntitlementDecision => new CafeteriaEntitlementDecision(
            true, null, null, CafeteriaEntitlementDecision::MODE_ENTITLED, $claims, $available,
            true, $isHoliday, $isExtra, $sequence, $weekStart, $weekEnd,
        );

        if ($mode === CafeteriaUsageMode::UseRemainingWeek) {
            if ($available === []) {
                return $deny('no_available_subsidy');
            }

            return $entitled(array_map(
                fn (string $date): array => $claim($date, $date === $todayString ? 'service_day' : 'advance'),
                $available,
            ));
        }

        if ($todayFree) {
            return $entitled([$claim($todayString, 'service_day')]);
        }

        // Today's entitlement is already used (here or at any other location):
        // the employee organization's extra-scan policy decides.
        return match ($policy->extra_scan_policy ?? CafeteriaExtraScanPolicy::Block) {
            CafeteriaExtraScanPolicy::EmployeePaid => $paid(),
            CafeteriaExtraScanPolicy::DeductNextAvailable => $futureFree === []
                ? $deny('no_available_subsidy')
                : $entitled([$claim($futureFree[0], 'extra_deduction')]),
            CafeteriaExtraScanPolicy::Block => $deny($isExtra ? 'already_scanned_today' : 'entitlement_already_consumed'),
        };
    }

    /**
     * Why the policy does not entitle a meal on this date, or null when it
     * does. A special subsidy day declared for the date (or the cafeteria)
     * overrides the weekday rule, as it always has.
     */
    public function nonEntitlementReason(CafeteriaServicePolicy $policy, Carbon $date, CafeteriaProvider $cafeteria): ?string
    {
        $special = $this->calendar->getSpecialDayForDate($date, $cafeteria);
        if ($special !== null && $special->is_open) {
            return $special->is_subsidy_day ? null : 'special_no_subsidy_day';
        }

        if ($policy->exclude_public_holidays && $this->calendar->isHoliday($date)) {
            return 'cafeteria_closed_holiday';
        }

        return $policy->isWeekdayEnabled($date) ? null : 'not_entitlement_day';
    }

    public function isOnLeave(Employee $employee, Carbon $date): bool
    {
        return EmployeeCafeteriaExclusion::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'active')
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->where(fn ($q) => $q->whereNull('return_to_work_on')->orWhereDate('return_to_work_on', '>', $date->toDateString()))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date->toDateString()))
            ->exists();
    }

    /**
     * Consumed entitlements per date, across every cafeteria.
     *
     * @param  list<string>  $dates
     * @return array<string, int>
     */
    public function usedSlots(Employee $employee, array $dates): array
    {
        if ($dates === []) {
            return [];
        }

        return CafeteriaTransactionConsumedDay::query()
            ->where('employee_id', $employee->id)
            ->where('entitlement_type', CafeteriaTransactionConsumedDay::ENTITLEMENT_MEAL)
            ->whereNotNull('active_key')
            // Dates may be stored with a time part on SQLite; compare by range.
            ->whereBetween('consumed_date', [min($dates), max($dates).' 23:59:59'])
            ->pluck('consumed_date')
            ->map(fn ($date): string => Carbon::parse($date)->toDateString())
            ->countBy()
            ->all();
    }

    /** Employee-paid service is a system default for days the policy does not entitle. */
    private function paidServiceAllowed(string $reason, Carbon $date): bool
    {
        return match (true) {
            $reason === 'cafeteria_closed_holiday' => $this->settings->get('holiday_scan_mode') === 'employee_payable',
            $date->isWeekend() && $reason === 'not_entitlement_day' => $this->settings->get('weekend_scan_mode') === 'employee_payable',
            default => false,
        };
    }

    private function withinOpeningHours(CafeteriaProvider $cafeteria, Carbon $at): bool
    {
        if (blank($cafeteria->opening_time) || blank($cafeteria->closing_time)) {
            return true;
        }

        $time = $at->format('H:i:s');
        $open = substr((string) $cafeteria->opening_time, 0, 8);
        $close = substr((string) $cafeteria->closing_time, 0, 8);

        // A closing time before the opening time spans midnight.
        return $open <= $close ? ($time >= $open && $time <= $close) : ($time >= $open || $time <= $close);
    }

    private function acceptedScansOn(Employee $employee, string $date): int
    {
        return CafeteriaTransaction::query()
            ->where('employee_id', $employee->id)
            ->whereDate('transaction_date', $date)
            ->where('status', CafeteriaTransactionStatus::Accepted)
            ->count();
    }

    /**
     * @param  list<string>  $dates
     * @return list<string>
     */
    private function withoutLeaveDays(Employee $employee, array $dates): array
    {
        if ($dates === []) {
            return [];
        }

        $exclusions = EmployeeCafeteriaExclusion::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'active')
            ->whereDate('starts_on', '<=', max($dates))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', min($dates)))
            ->get();

        return array_values(array_filter(
            $dates,
            fn (string $date): bool => $exclusions->doesntContain(fn (EmployeeCafeteriaExclusion $exclusion): bool => $exclusion->isActiveOn(Carbon::parse($date))),
        ));
    }
}
