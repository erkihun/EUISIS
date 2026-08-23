<?php

declare(strict_types=1);

namespace CafeteriaSystem\Services;

use CafeteriaSystem\Models\CafeteriaPublicHoliday;
use CafeteriaSystem\Models\CafeteriaServiceTransaction;
use CafeteriaSystem\Models\CafeteriaSetting;
use CafeteriaSystem\Models\CafeteriaSpecialDay;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;

/**
 * Per-day status for the scan calendar.
 *
 * Mirrors the EUISIS cafeteria terminal so an operator reads the same colours
 * in both systems. Precedence, highest first:
 *
 *   consumed → employee leave → public holiday / no-subsidy → special open day
 *   → available → closed
 *
 * Leave is EUISIS employee data and arrives as a caller-supplied list of dates
 * fetched over the API; this service never queries the EUISIS database.
 */
readonly class CafeteriaCalendarService
{
    /**
     * Day metadata for a date range.
     *
     * @param  array<int, string>  $consumedDates  ISO dates already served
     * @param  array<int, string>  $leaveDates  ISO dates the employee is on leave
     * @return array<int, array<string, mixed>>
     */
    public function days(
        ?string $providerId,
        ?string $cafeteriaId,
        CarbonInterface $from,
        CarbonInterface $to,
        array $consumedDates = [],
        array $leaveDates = [],
    ): array {
        $settings = $this->settings($providerId);

        $closedOnWeekend = filter_var($settings['closed_weekend_default'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $excludeHolidays = filter_var($settings['exclude_public_holidays'] ?? true, FILTER_VALIDATE_BOOLEAN);

        $holidays = $this->holidayDates($providerId, $from, $to);
        $specials = $this->specialDays($providerId, $from, $to);

        // Served dates recorded locally, unioned with anything the caller knows.
        $served = $this->servedDates($cafeteriaId, $from, $to) + array_flip($consumedDates);
        $leave = array_flip($leaveDates);

        $days = [];

        foreach (CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()) as $date) {
            $iso = $date->toDateString();
            $special = $specials[$iso] ?? null;

            $isWeekend = $date->isSaturday() || $date->isSunday();
            $isHoliday = isset($holidays[$iso]);

            // A special day is the override, so it decides openness outright.
            $isOpen = $special !== null
                ? (bool) $special['is_open']
                : ! ($isWeekend && $closedOnWeekend) && ! ($isHoliday && $excludeHolidays);

            $isSubsidyDay = $special !== null ? (bool) $special['is_subsidy_day'] : $isOpen;
            $isConsumed = isset($served[$iso]);
            $onLeave = isset($leave[$iso]);
            $isAvailable = $isOpen && $isSubsidyDay && ! $isConsumed && ! $onLeave;

            $days[] = [
                'date' => $iso,
                'day_name' => $date->format('D'),
                'is_today' => $date->isToday(),
                'is_working_day' => ! $isWeekend,
                'is_open' => $isOpen,
                'is_subsidy_day' => $isSubsidyDay,
                'is_public_holiday' => $isHoliday,
                'is_special_day' => $special !== null,
                'is_employee_excluded' => $onLeave,
                'is_consumed' => $isConsumed,
                'is_available' => $isAvailable,
                'reason_code' => $this->reasonCode($isConsumed, $onLeave, $isHoliday, $special, $isOpen, $isAvailable),
                'label' => $this->label($iso, $isConsumed, $onLeave, $isHoliday, $special, $isOpen, $isAvailable),
            ];
        }

        return $days;
    }

    /**
     * @param  array<string, mixed>|null  $special
     */
    private function reasonCode(
        bool $isConsumed,
        bool $onLeave,
        bool $isHoliday,
        ?array $special,
        bool $isOpen,
        bool $isAvailable,
    ): string {
        return match (true) {
            $isConsumed => 'consumed',
            $onLeave => 'employee_on_leave',
            $special !== null && ! $special['is_subsidy_day'] => 'special_no_subsidy_day',
            $special !== null && $isOpen => 'special_open_day',
            $isHoliday => 'public_holiday',
            ! $isOpen => 'closed',
            $isAvailable => 'available',
            default => 'not_available',
        };
    }

    /**
     * @param  array<string, mixed>|null  $special
     */
    private function label(
        string $iso,
        bool $isConsumed,
        bool $onLeave,
        bool $isHoliday,
        ?array $special,
        bool $isOpen,
        bool $isAvailable,
    ): string {
        return match (true) {
            $isConsumed => $iso.' — already served',
            $onLeave => $iso.' — employee on leave',
            $special !== null => $iso.' — '.$special['name'],
            $isHoliday => $iso.' — public holiday',
            ! $isOpen => $iso.' — closed',
            $isAvailable => $iso.' — available',
            default => $iso,
        };
    }

    /** @return array<string, true> */
    private function holidayDates(?string $providerId, CarbonInterface $from, CarbonInterface $to): array
    {
        $dates = [];

        foreach (CafeteriaPublicHoliday::query()->forProvider($providerId)->get() as $holiday) {
            $date = $holiday->holiday_date;

            if ($date === null) {
                continue;
            }

            if (! $holiday->is_recurring) {
                if ($date->betweenIncluded($from, $to)) {
                    $dates[$date->toDateString()] = true;
                }

                continue;
            }

            // A recurring holiday lands on the same month/day in each year the
            // range spans, so a range crossing new year matches both.
            foreach (range((int) $from->year, (int) $to->year) as $year) {
                $candidate = Carbon::create($year, (int) $date->month, (int) $date->day);

                if ($candidate !== false && $candidate->betweenIncluded($from, $to)) {
                    $dates[$candidate->toDateString()] = true;
                }
            }
        }

        return $dates;
    }

    /** @return array<string, array<string, mixed>> */
    private function specialDays(?string $providerId, CarbonInterface $from, CarbonInterface $to): array
    {
        return CafeteriaSpecialDay::query()
            ->forProvider($providerId)
            // whereDate, not whereBetween: SQLite stores these as datetime
            // strings, so comparing against bare dates silently misses rows.
            ->whereDate('special_date', '>=', $from->toDateString())
            ->whereDate('special_date', '<=', $to->toDateString())
            ->get()
            ->mapWithKeys(fn (CafeteriaSpecialDay $day): array => [
                $day->special_date->toDateString() => [
                    'name' => $day->name_en,
                    'day_type' => $day->day_type,
                    'is_open' => $day->is_open,
                    'is_subsidy_day' => $day->is_subsidy_day,
                ],
            ])->all();
    }

    /** @return array<string, true> */
    private function servedDates(?string $cafeteriaId, CarbonInterface $from, CarbonInterface $to): array
    {
        return CafeteriaServiceTransaction::query()
            ->when($cafeteriaId !== null, fn ($query) => $query->where('cafeteria_id', $cafeteriaId))
            ->where('status', 'served')
            // See specialDays(): date columns need whereDate to compare
            // consistently on both SQLite and MySQL.
            ->whereDate('service_date', '>=', $from->toDateString())
            ->whereDate('service_date', '<=', $to->toDateString())
            ->pluck('service_date')
            ->mapWithKeys(fn ($date): array => [
                ($date instanceof CarbonInterface ? $date->toDateString() : (string) $date) => true,
            ])->all();
    }

    /**
     * Stored settings merged over the registry defaults.
     *
     * @return array<string, mixed>
     */
    private function settings(?string $providerId): array
    {
        $defaults = [];

        foreach (CafeteriaSettingsRegistry::definition() as $fields) {
            foreach ($fields as $key => $definition) {
                $defaults[$key] = $definition['default'];
            }
        }

        $stored = CafeteriaSetting::query()
            ->where('provider_id', $providerId)
            ->pluck('value', 'key')
            ->all();

        return array_merge($defaults, $stored);
    }
}
