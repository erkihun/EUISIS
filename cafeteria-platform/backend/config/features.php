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
    | Staged rollout inside Transport (docs/transport/production-readiness.md).
    | Each sub-feature can be switched independently for a pilot; the core
    | boarding flow (QR, trips, manifests, reports) follows the module flag.
    |
    | transport_nfc        — NFC boarding through the device API and the portal.
    | transport_offline    — offline boarding sync (NEEDS_POLICY_DECISION_OFFLINE_BOARDING).
    | transport_settlement — contracts, invoices, statements and reconciliation.
    | transport_gps        — vehicle position reports from devices.
    */
    'transport_nfc' => (bool) env('FEATURE_TRANSPORT_NFC', false),
    'transport_offline' => (bool) env('FEATURE_TRANSPORT_OFFLINE', false),
    'transport_settlement' => (bool) env('FEATURE_TRANSPORT_SETTLEMENT', true),
    'transport_gps' => (bool) env('FEATURE_TRANSPORT_GPS', false),

    /*
    | Route-name prefixes owned by each gated module.
    */
    'route_prefixes' => [
        'transport' => ['transport.', 'provider.portal.transport.', 'api.transport.'],
        'transport_settlement' => [
            'transport.contracts.', 'transport.invoices.', 'transport.settlements.',
            'provider.portal.transport.invoices.', 'provider.portal.transport.settlements.',
        ],
        'transport_gps' => ['api.transport.positions.'],
        'court_cases' => ['court-cases.'],
    ],

    /*
    | Provider service codes owned by each gated module.
    */
    'service_codes' => [
        'transport' => ['transport'],
    ],
];
