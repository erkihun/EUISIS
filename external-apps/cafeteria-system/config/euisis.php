<?php

declare(strict_types=1);

/**
 * EUISIS integration settings.
 *
 * The token is issued by EUISIS → System Settings → API Management and is read
 * from the environment only; it is never committed or written to the database.
 */
return [
    'base_url' => env('EUISIS_API_BASE_URL', 'http://127.0.0.1:8000'),
    'token' => env('EUISIS_API_TOKEN', ''),
    'timeout' => (int) env('EUISIS_API_TIMEOUT', 10),
    'provider_code' => env('CAFETERIA_PROVIDER_CODE', ''),
    'scan_rate_limit' => (int) env('CAFETERIA_SCAN_RATE_LIMIT', 30),

    /** Scopes this application must be granted in EUISIS API Management. */
    'required_scopes' => [
        'id_cards.verify',
        'employees.basic_verify',
        'service_eligibility.check',
        'service_transactions.create',
    ],
];
