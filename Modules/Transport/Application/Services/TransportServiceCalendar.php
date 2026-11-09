<?php

declare(strict_types=1);

namespace Modules\Transport\Application\Services;

use App\Services\Calendar\PublicHolidayCalendar;
use Illuminate\Support\Carbon;
use Modules\Transport\Domain\Models\TransportSchedule;
use Modules\Transport\Domain\Models\TransportServiceCalendarDay;

/**
 * Whether a schedule runs on a date: its weekdays and effective period, the
 * city's public holidays (the central calendar, shared with the other
 * modules), and the transport-specific operating / suspended days a
 * dispatcher records for the city, a provider or one route.
 */
class TransportServiceCalendar
{
    public function __construct(private readonly PublicHolidayCalendar $holidays) {}

    public function runsOn(TransportSchedule $schedule, Carbon $date): bool
    {
        $override = $this->override($schedule, $date);
        if ($override !== null) {
            return $override === TransportServiceCalendarDay::KIND_OPERATING;
        }

        if (! $schedule->runsOn($date)) {
            return false;
        }

        return ! ($schedule->skips_public_holidays && $this->holidays->isHoliday($date));
    }

    /** The most specific calendar override for the date: route, then provider, then city. */
    private function override(TransportSchedule $schedule, Carbon $date): ?string
    {
        $rows = TransportServiceCalendarDay::query()
            ->whereDate('calendar_date', $date->toDateString())
            ->where(function ($query) use ($schedule): void {
                $query->where('transport_route_id', $schedule->transport_route_id)
                    ->orWhere(fn ($q) => $q->whereNull('transport_route_id')->where('provider_id', $schedule->provider_id))
                    ->orWhere(fn ($q) => $q->whereNull('transport_route_id')->whereNull('provider_id'));
            })
            ->get();

        $pick = $rows->firstWhere('transport_route_id', $schedule->transport_route_id)
            ?? $rows->first(fn ($row) => $row->transport_route_id === null && $row->provider_id === $schedule->provider_id)
            ?? $rows->first(fn ($row) => $row->transport_route_id === null && $row->provider_id === null);

        return $pick?->kind;
    }
}
