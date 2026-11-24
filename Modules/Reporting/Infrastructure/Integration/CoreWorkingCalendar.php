<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Integration;

use App\Backbone\Contracts\WorkingDays;
use DateTimeImmutable;
use Modules\Reporting\Application\Ports\WorkingCalendar;
use Modules\Reporting\Domain\Enums\HolidayAdjustment;

/** Working days and holidays through the Core's WorkingDays contract. */
final readonly class CoreWorkingCalendar implements WorkingCalendar
{
    public function __construct(private WorkingDays $days) {}

    public function adjust(DateTimeImmutable $moment, HolidayAdjustment $adjustment): DateTimeImmutable
    {
        $adjusted = match ($adjustment) {
            HolidayAdjustment::None => $moment,
            HolidayAdjustment::NextWorkingDay => $this->days->onOrAfter($moment),
            HolidayAdjustment::PreviousWorkingDay => $this->days->onOrBefore($moment),
        };

        // The calendar answers in the business timezone; give the caller back its own.
        return $adjusted->setTimezone($moment->getTimezone());
    }

    public function addWorkingDays(DateTimeImmutable $from, int $days): DateTimeImmutable
    {
        return $this->days->addWorkingDays($from, $days)->setTimezone($from->getTimezone());
    }
}
