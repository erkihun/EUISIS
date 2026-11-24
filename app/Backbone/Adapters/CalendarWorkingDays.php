<?php

declare(strict_types=1);

namespace App\Backbone\Adapters;

use App\Backbone\Contracts\WorkingDays;
use App\Services\Calendar\WorkCalendarService;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/** The existing work calendar (work week setting + public holidays), read in the business timezone. */
final readonly class CalendarWorkingDays implements WorkingDays
{
    private const TIMEZONE = 'Africa/Addis_Ababa';

    /** A year of non-working days in a row means the calendar is misconfigured; stop rather than loop. */
    private const MAX_STEPS = 366;

    public function __construct(private WorkCalendarService $calendar) {}

    public function isWorkingDay(DateTimeInterface $date): bool
    {
        return $this->calendar->isWorkingDay($this->local($date));
    }

    public function addWorkingDays(DateTimeInterface $from, int $days): DateTimeImmutable
    {
        $cursor = $this->local($from);
        for ($steps = 0; $days > 0 && $steps < self::MAX_STEPS * 4; $steps++) {
            $cursor->addDay();
            if ($this->calendar->isWorkingDay($cursor)) {
                $days--;
            }
        }

        return $cursor->toDateTimeImmutable();
    }

    public function onOrAfter(DateTimeInterface $date): DateTimeImmutable
    {
        return $this->shift($date, 1);
    }

    public function onOrBefore(DateTimeInterface $date): DateTimeImmutable
    {
        return $this->shift($date, -1);
    }

    private function shift(DateTimeInterface $date, int $direction): DateTimeImmutable
    {
        $cursor = $this->local($date);
        for ($steps = 0; $steps < self::MAX_STEPS && ! $this->calendar->isWorkingDay($cursor); $steps++) {
            $cursor->addDays($direction);
        }

        return $cursor->toDateTimeImmutable();
    }

    private function local(DateTimeInterface $date): Carbon
    {
        return Carbon::instance($date)->setTimezone(self::TIMEZONE);
    }
}
