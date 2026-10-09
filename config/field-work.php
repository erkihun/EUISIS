<?php

declare(strict_types=1);

/*
 * Field Work Management — docs/field-work-management.md.
 *
 * GPS is captured only at lifecycle events (check-in / check-out); there is
 * no continuous tracking setting on purpose.
 */
return [
    'gps' => [
        // A capture less precise than this cannot prove presence: LOW_ACCURACY.
        'max_acceptable_accuracy_m' => (int) env('FIELD_WORK_GPS_MAX_ACCURACY_M', 100),
        // Reject a capture taken longer ago than this (stale or replayed).
        'max_capture_age_minutes' => (int) env('FIELD_WORK_GPS_MAX_CAPTURE_AGE_MINUTES', 10),
        // Tolerated device clock drift into the future.
        'max_future_skew_minutes' => (int) env('FIELD_WORK_GPS_MAX_FUTURE_SKEW_MINUTES', 2),
        // NEEDS_DECISION: whether an OUTSIDE_EXPECTED_AREA capture is refused
        // or recorded and flagged. Default: recorded and flagged.
        'block_outside_expected_area' => (bool) env('FIELD_WORK_GPS_BLOCK_OUTSIDE_AREA', false),
        'max_geofence_radius_m' => 50000,
    ],

    'check_in' => [
        // How early before the planned start a participant may check in.
        'early_minutes' => (int) env('FIELD_WORK_CHECK_IN_EARLY_MINUTES', 120),
    ],

    'participants' => [
        'max' => 30,
    ],
];
