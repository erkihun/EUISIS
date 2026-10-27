<?php

declare(strict_types=1);

namespace App\Services\Cafeteria;

use App\Models\CafeteriaDayRule;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaSpecialDay;
use App\Models\PublicHoliday;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class WorkingDayCalendarService
{
    public function __construct(private readonly CafeteriaSettingsService $settings) {}

    /** @var Collection<int, string>|null */
    private ?Collection $holidayCache = null;

    /** @var Collection<int, CafeteriaDayRule>|null */
    private ?Collection $dayRuleCache = null;

    public function isHoliday(Carbon $date): bool
    {
        return $this->getHolidayDates()->contains($date->toDateString());
    }

    public function isWeekend(Carbon $date): bool
    {
        return $date->isWeekend();
    }

    /**
     * Uses the same subsidy calendar as scans: special overrides, explicit
     * day rules, and the configured holiday/weekend defaults.
     * The legacy second parameter remains for caller compatibility.
     */
    public function isWorkingDay(Carbon $date, bool $excludeWeekends = true, ?CafeteriaProvider $provider = null): bool
    {
        return $this->isSubsidyDay($date, $provider);
    }

    /**
     * Returns true if cafeteria is open (may have no subsidy).
     */
    public function isCafeteriaOpen(Carbon $date, ?CafeteriaProvider $provider = null): bool
    {
        $special = $this->getSpecialDayForDate($date, $provider);
        if ($special !== null) {
            return $special->is_open;
        }

        $rule = $this->getDayRule($date);
        if ($rule !== null) {
            return $rule->is_open;
        }

        if ($this->isHoliday($date) && $this->settings->get('holiday_scan_mode') === 'reject') {
            return false;
        }

        if (! $date->isWeekend()) {
            return true;
        }

        return ! $this->settings->getBool('closed_weekend_default')
            && $this->settings->getBool($date->isSaturday() ? 'allow_saturday_service' : 'allow_sunday_service')
            && $this->settings->get('weekend_scan_mode') !== 'reject';
    }

    /**
     * Returns true when $date qualifies for subsidy allocation.
     */
    public function isSubsidyDay(Carbon $date, ?CafeteriaProvider $provider = null): bool
    {
        $special = $this->getSpecialDayForDate($date, $provider);
        if ($special !== null) {
            return $special->is_open && $special->is_subsidy_day;
        }

        if (! $this->isCafeteriaOpen($date, $provider)) {
            return false;
        }

        if ($this->isHoliday($date) && ($this->settings->getBool('exclude_public_holidays') || $this->settings->get('holiday_scan_mode') !== 'allow')) {
            return false;
        }

        if ($date->isWeekend() && $this->settings->get('weekend_scan_mode') === 'employee_payable') {
            return false;
        }

        $rule = $this->getDayRule($date);

        return $rule === null || ($rule->is_open && $rule->is_subsidy_day);
    }

    public function nextWorkingDay(Carbon $date, bool $excludeWeekends = true): Carbon
    {
        $next = $date->copy()->addDay();

        while (! $this->isWorkingDay($next, $excludeWeekends)) {
            $next->addDay();
        }

        return $next;
    }

    public function workingDaysBetween(Carbon $start, Carbon $end, bool $excludeWeekends = true): int
    {
        $count = 0;
        $current = $start->copy()->startOfDay();
        $endDay = $end->copy()->startOfDay();

        while ($current->lte($endDay)) {
            if ($this->isWorkingDay($current, $excludeWeekends)) {
                $count++;
            }
            $current->addDay();
        }

        return $count;
    }

    public function holidaysBetween(Carbon $start, Carbon $end): Collection
    {
        return PublicHoliday::query()
            ->where('is_active', true)
            ->whereBetween('holiday_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('holiday_date')
            ->get();
    }

    public function getHolidayForDate(Carbon $date): ?PublicHoliday
    {
        return PublicHoliday::query()
            ->where('is_active', true)
            ->whereDate('holiday_date', $date->toDateString())
            ->first();
    }

    public function getSpecialDayForDate(Carbon $date, ?CafeteriaProvider $provider = null): ?CafeteriaSpecialDay
    {
        return CafeteriaSpecialDay::query()
            ->where('is_active', true)
            ->whereNull('deleted_at')
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

    public function getDayRule(Carbon $date): ?CafeteriaDayRule
    {
        $isoDay = (int) $date->isoFormat('E'); // 1=Mon … 7=Sun

        return $this->getDayRules()->first(fn (CafeteriaDayRule $r) => $r->day_of_week === $isoDay
            && ($r->effective_from === null || $r->effective_from->lte($date->copy()->startOfDay()))
            && ($r->effective_to === null || $r->effective_to->gte($date->copy()->startOfDay())));
    }

    public function clearCache(): void
    {
        $this->holidayCache = null;
        $this->dayRuleCache = null;
    }

    private function getHolidayDates(): Collection
    {
        if ($this->holidayCache === null) {
            $this->holidayCache = PublicHoliday::query()
                ->where('is_active', true)
                ->pluck('holiday_date')
                ->map(fn ($d) => Carbon::parse($d)->toDateString());
        }

        return $this->holidayCache;
    }

    private function getDayRules(): Collection
    {
        if ($this->dayRuleCache === null) {
            $this->dayRuleCache = CafeteriaDayRule::query()
                ->where('is_active', true)
                ->get();
        }

        return $this->dayRuleCache;
    }
}
