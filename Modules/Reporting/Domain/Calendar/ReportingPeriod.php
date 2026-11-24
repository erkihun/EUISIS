<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Calendar;

/**
 * One reporting period. Dates are Gregorian civil dates (Y-m-d), inclusive,
 * whatever calendar produced them; `key` identifies the period within a
 * schedule and never changes with the interface language.
 */
final readonly class ReportingPeriod
{
    public function __construct(
        public string $key,
        public string $startDate,
        public string $endDate,
        public string $labelEn,
        public string $labelAm,
    ) {}
}
