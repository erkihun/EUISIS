<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Module feature flags
    |--------------------------------------------------------------------------
    |
    | Modules that exist in the codebase but are NOT part of the current
    | production scope. While a flag is false the module's routes answer 404,
    | its navigation and provider-portal entries are hidden and no provider is
    | treated as offering the service. Code, tables and data are kept intact.
    |
    | transport   — Transport Management: live (docs/transport-provider-module.md).
    |               FEATURE_TRANSPORT=false switches it off again without
    |               touching its data.
    | court_cases — Court Cases: planned module, placeholder page only.
    */
    'transport' => (bool) env('FEATURE_TRANSPORT', true),
    'court_cases' => (bool) env('FEATURE_COURT_CASES', false),

    /*
    | Route-name prefixes owned by each gated module.
    */
    'route_prefixes' => [
        'transport' => ['transport.', 'provider.portal.transport.'],
        'court_cases' => ['court-cases.'],
    ],

    /*
    | Provider service codes owned by each gated module.
    */
    'service_codes' => [
        'transport' => ['transport'],
    ],
];
