<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cafeteria platform (the cafeteria module as its own system)
|--------------------------------------------------------------------------
|
| docs/integration/cafeteria-platform.md, ADR 0014. The cafeteria module runs
| in cafeteria-platform/ with its own database; it reads EUISIS master data
| over the EUISIS API, and EUISIS reads cafeteria results back over the
| platform's API with the token of an API client registered there.
|
| mode:
|   local    — EUISIS keeps serving its own cafeteria module (the default).
|   platform — the cafeteria pages of EUISIS send people to the platform (the
|              same paths there), and the employee portal reads an employee's
|              cafeteria standing from the platform.
|
*/

return [
    'mode' => in_array(env('CAFETERIA_PLATFORM_MODE', 'local'), ['local', 'platform'], true) ? env('CAFETERIA_PLATFORM_MODE', 'local') : 'local',

    // Base URL of the platform, e.g. https://cafeteria.example.gov.et (no trailing slash).
    'url' => rtrim((string) env('CAFETERIA_PLATFORM_URL', ''), '/'),

    // The token of the API client registered on the platform for EUISIS (scope cafeteria:read).
    'token' => env('CAFETERIA_PLATFORM_TOKEN'),

    'timeout' => (int) env('CAFETERIA_PLATFORM_TIMEOUT', 10),

    'verify_tls' => (bool) env('CAFETERIA_PLATFORM_VERIFY_TLS', true),
];
