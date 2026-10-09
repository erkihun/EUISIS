<?php

declare(strict_types=1);

namespace App\Services\Cafeteria;

use App\Enums\CafeteriaTransactionStatus;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaServicePolicy;
use App\Models\CafeteriaSpecialDay;
use App\Models\CafeteriaTransaction;
use App\Models\CafeteriaTransactionConsumedDay;
use App\Models\Employee;
use App\Models\EmployeeCafeteriaExclusion;
use App\Services\Cafeteria\Policy\CafeteriaEntitlementService;
use App\Services\Cafeteria\Policy\CafeteriaPolicyResolver;
use Illuminate\Support\Carbon;

class CafeteriaCalendarService
{
    public function __construct(private readonly WorkingDayCalendarService $workingDays, private readonly CafeteriaWeekWindowService $weekWindow, private readonly CafeteriaSettingsService $settings) {}

    /** @return list<array<string, mixed>> */
    public function getEmployeeWeekCalendar(?Employee $employee, Carbon $date, ?CafeteriaProvider $provider = null): array
    {
        $weekStart = $this->weekWindow->weekStart($date);
        $weekEnd = $weekStart->copy()->addDays(6);
        $consumed = $employee === null ? [] : $this->getConsumedDatesForWeek($employee, $weekStart, $weekEnd);

        // For a known employee at a known cafeteria, entitlement days are the
        // employee organization's policy; with no policy nothing is available.
        $policy = null;
        $blocked = false;
        if ($employee !== null && $provider !== null) {
            $resolution = app(CafeteriaPolicyResolver::class)->resolve($employee, $provider, $date);
            $policy = $resolution->policy;
            $blocked = ! $resolution->resolved();
        }

        $days = [];
        $current = $weekStart->copy();

        while ($current->lte($weekEnd)) {
            $days[] = $this->buildDay($current, $employee, $provider, $consumed, $policy, $blocked);
            $current->addDay();
        }

        return $days;
    }

    /** @return list<string> */
    public function markConsumedDaysForTransaction(CafeteriaTransaction $transaction): array
    {
        $transaction->loadMissing('consumedDays');

        return $transaction->consumedDays
            ->whereNull('reversed_at')
            ->pluck('consumed_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->values()
            ->all();
    }

    /** @return array<string, CafeteriaTransactionConsumedDay> */
    public function getConsumedDatesForWeek(Employee $employee, Carbon $weekStart, Carbon $weekEnd): array
    {
        return CafeteriaTransactionConsumedDay::query()
            ->with('transaction:id,status')
            ->where('employee_id', $employee->id)
            ->whereNull('reversed_at')
            ->whereBetween('consumed_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->whereHas('transaction', fn ($query) => $query->where('status', CafeteriaTransactionStatus::Accepted))
            ->get()
            ->keyBy(fn (CafeteriaTransactionConsumedDay $day) => $day->consumed_date->toDateString())
            ->all();
    }

    /**
     * @param  array<string, CafeteriaTransactionConsumedDay>  $consumed
     * @return array<string, mixed>
     */
    private function buildDay(Carbon $date, ?Employee $employee, ?CafeteriaProvider $provider, array $consumed, ?CafeteriaServicePolicy $policy = null, bool $blocked = false): array
    {
        $dateString = $date->toDateString();
        $holiday = $this->workingDays->getHolidayForDate($date);
        $special = $this->specialDayForDate($date, $provider);
        $exclusion = $employee === null
            ? null
            : EmployeeCafeteriaExclusion::query()
                ->where('employee_id', $employee->id)
                ->whereDate('starts_on', '<=', $dateString)
                ->where(function ($query) use ($dateString): void {
                    $query->whereNull('return_to_work_on')->orWhereDate('return_to_work_on', '>', $dateString);
                })
                ->where(function ($query) use ($dateString): void {
                    $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $dateString);
                })
                ->where('status', 'active')
                ->first();

        $isOpen = $this->workingDays->isCafeteriaOpen($date, $provider);
        $isSubsidyDay = $policy !== null && $provider !== null
            ? $isOpen && app(CafeteriaEntitlementService::class)->nonEntitlementReason($policy, $date, $provider) === null
            : ! $blocked && $this->workingDays->isSubsidyDay($date, $provider);
        $isInWindow = $date->lte($this->weekWindow->weekEnd($date));
        $excludesLeave = $exclusion !== null && $this->settings->getBool('exclude_leave_days_from_subsidy');
        $isConsumed = isset($consumed[$dateString]);
        $isPast = $date->lt(Carbon::today());

        $reasonCode = match (true) {
            $isConsumed => 'consumed',
            $blocked => 'no_policy',
            $excludesLeave => 'employee_leave',
            $special !== null && ! $special->is_open => 'special_closed_day',
            $special !== null && ! $special->is_subsidy_day => 'special_no_subsidy_day',
            $holiday !== null && ! $isSubsidyDay => 'public_holiday',
            ! $isOpen => $date->isWeekend() ? 'weekend_closed' : 'closed',
            ! $isSubsidyDay => 'closed',
            $isPast || ! $isInWindow => 'past_unclaimable',
            $special !== null && $special->is_open => 'special_open_day',
            default => $date->isWeekend() ? 'weekend_available' : 'available',
        };

        return [
            'date' => $dateString,
            'day_name' => $date->format('l'),
            'is_today' => $date->isSameDay(Carbon::today()),
            'is_working_day' => $this->workingDays->isWorkingDay($date, true, $provider),
            'is_open' => $isOpen,
            'is_subsidy_day' => $isSubsidyDay,
            'is_public_holiday' => $holiday !== null,
            'is_special_day' => $special !== null,
            'is_employee_excluded' => $exclusion !== null,
            'is_consumed' => $isConsumed,
            'consumed_by_transaction_id' => $consumed[$dateString]->cafeteria_transaction_id ?? null,
            'is_available' => ! $isConsumed && ! $excludesLeave && $isOpen && $isSubsidyDay && ! $isPast && $isInWindow,
            'reason_code' => $reasonCode,
            'label' => $this->labelForReason($reasonCode),
        ];
    }

    private function specialDayForDate(Carbon $date, ?CafeteriaProvider $provider): ?CafeteriaSpecialDay
    {
        return CafeteriaSpecialDay::query()
            ->where('is_active', true)
            ->whereDate('special_date', $date->toDateString())
            ->where(function ($query) use ($provider): void {
                $query->whereNull('cafeteria_provider_id');

                if ($provider !== null) {
                    $query->orWhere('cafeteria_provider_id', $provider->id);
                }
            })
            ->orderByRaw('cafeteria_provider_id is null')
            ->first();
    }

    private function labelForReason(string $reasonCode): string
    {
        return __('cafeteria.calendar_'.$reasonCode);
    }
}
