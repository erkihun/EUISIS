<?php

declare(strict_types=1);

/**
 * CORS configuration for EUISIS.
 *
 * API routes are consumed by:
 *   - Cafeteria scan terminals (same origin or known internal IP range)
 *   - Provider portal SPA (same origin)
 *   - Employee portal SPA (same origin)
 *
 * In production, set CORS_ALLOWED_ORIGINS to the exact HTTPS domain(s),
 * e.g. https://euisis.addisababa.gov.et — never use '*' in production.
 *
 * PRODUCTION CHECKLIST:
 *   CORS_ALLOWED_ORIGINS=https://euisis.addisababa.gov.et
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    | Paths that CORS headers will be sent for.
    */
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    /*
    | Allowed HTTP methods.
    */
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    /*
    | Allowed origins.
    | Set CORS_ALLOWED_ORIGINS in .env to a comma-separated list of allowed
    | origins. Defaults to same-origin only (empty = no cross-origin requests).
    |
    | Examples:
    |   CORS_ALLOWED_ORIGINS=https://euisis.addisababa.gov.et
    |   CORS_ALLOWED_ORIGINS=https://euisis.addisababa.gov.et,https://admin.euisis.example
    */
    /*
    | '*' is dropped on purpose: with supports_credentials the CORS layer would
    | reflect ANY origin and let every website make signed-in requests. Name
    | each allowed origin explicitly.
    */
    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),
        static fn (string $origin): bool => $origin !== '' && $origin !== '*',
    )),

    'allowed_origins_patterns' => [],

    /*
    | Allowed headers.
    */
    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'X-XSRF-TOKEN', 'Accept'],

    /*
    | Headers exposed to the browser.
    */
    'exposed_headers' => [],

    /*
    | Max age for the preflight cache (seconds).
    */
    'max_age' => 3600,

    /*
    | Allow credentials (cookies, auth headers).
    | Must be true for Sanctum SPA authentication to work cross-origin.
    | Only safe when allowed_origins is a specific domain, NOT '*'.
    */
    'supports_credentials' => true,

];
