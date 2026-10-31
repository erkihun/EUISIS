<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| court-ms (tribunal case system) integration
|--------------------------------------------------------------------------
|
| docs/integration/court-ms.md, ADR 0013. EUISIS sends tribunal referrals to
| court-ms and polls the status of the cases they opened. The token is the
| integration token court-ms prints with `php artisan euisis:integration-token`;
| it lives in the environment only.
|
*/

return [
    'enabled' => (bool) env('COURT_MS_ENABLED', false),

    // Base URL of court-ms, e.g. https://court.example.gov.et (no trailing slash).
    'url' => rtrim((string) env('COURT_MS_URL', ''), '/'),

    'token' => env('COURT_MS_TOKEN'),

    'timeout' => (int) env('COURT_MS_TIMEOUT', 20),

    'verify_tls' => (bool) env('COURT_MS_VERIFY_TLS', true),

    // Flows 4 and 6: My Portal > My court cases lists the employee's tribunal cases, and each
    // case is followed and answered there (the token needs court-ms's people:read and people:act).
    'employee_portal' => (bool) env('COURT_MS_EMPLOYEE_PORTAL', false),

    // Grievance category code => court-ms case type id, the referral form's default choice.
    // JSON, e.g. {"DISMISSAL":3,"TRANSFER":4}; the officer can still pick another type.
    'case_type_map' => json_decode((string) env('COURT_MS_CASE_TYPE_MAP', '{}'), true) ?: [],

    'default_case_type_id' => env('COURT_MS_DEFAULT_CASE_TYPE_ID') !== null ? (int) env('COURT_MS_DEFAULT_CASE_TYPE_ID') : null,

    // Evidence of the grievance the officer may attach (PDF only, as court-ms accepts).
    'max_documents' => 10,

    'max_document_bytes' => 10 * 1024 * 1024,
];
