<?php

declare(strict_types=1);

return [
    /*
     * Working weekdays (ISO, 1 = Monday) used for statutory working-day
     * deadlines under Regulation 201/2018. Public holidays always come from
     * the shared PublicHolidayCalendar. NEEDS_DECISION: confirm the official
     * civil-service work week with the Public Service and HR Development
     * Bureau; Monday–Friday is the starting value.
     */
    'work_week_days' => array_values(array_map('intval', array_filter(
        explode(',', (string) env('CIVIL_SERVICE_WORK_WEEK_DAYS', '1,2,3,4,5')),
        static fn (string $day): bool => $day !== '',
    ))),
];
