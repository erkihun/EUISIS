<?php

declare(strict_types=1);

/*
| The temporary pilot week dashboard: one unlisted page that shows, per
| institution, what was registered this week against the training plan.
|
| It needs no sign-in. It is reached only through its own link, which carries
| a secret token; nothing on the public site or in the application links to
| it. It shows counts only, never a person's name. It switches itself off
| after `until`.
|
| The link is printed by:  php artisan pilot:dashboard-link
*/
return [
    'enabled' => (bool) env('PILOT_DASHBOARD_ENABLED', true),

    // Last day the page answers (inclusive, application timezone).
    'until' => env('PILOT_DASHBOARD_UNTIL', '2026-12-31'),

    // The secret part of the link. Left empty, it is derived from APP_KEY,
    // so it is stable on one server and different on every other.
    'token' => env('PILOT_DASHBOARD_TOKEN'),

    // Comma-separated organization codes to show. Empty shows every active,
    // non-demo organization.
    'organization_codes' => array_values(array_filter(array_map('trim', explode(',', (string) env('PILOT_DASHBOARD_ORGANIZATIONS', ''))))),

    // Seconds the figures are cached, so a phone refreshing the page does
    // not query the database each time.
    'cache_seconds' => (int) env('PILOT_DASHBOARD_CACHE_SECONDS', 60),
];
