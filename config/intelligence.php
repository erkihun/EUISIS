<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Addis One Intelligence (docs/intelligence-architecture.md)
|--------------------------------------------------------------------------
|
| Environment-driven infrastructure settings. Behavioural switches, limits
| and detection thresholds live in System Settings › Intelligence
| (SystemSettingsRegistry::GROUP_INTELLIGENCE). Provider secrets live here
| and nowhere else; the UI only ever says "configured" or "not configured".
|
*/
return [
    'telemetry' => [
        // 0 = decide from the cache store (exact on redis/memcached/apcu, 1-in-20 sampling on database/file).
        'sample_every' => (int) env('INTELLIGENCE_TELEMETRY_SAMPLE_EVERY', 0),
        // Minutes of request counters the collector flushes per run (must exceed the schedule interval).
        'flush_minutes' => (int) env('INTELLIGENCE_TELEMETRY_FLUSH_MINUTES', 10),
        // A query running longer than this many seconds counts as long-running.
        'long_query_seconds' => (int) env('INTELLIGENCE_LONG_QUERY_SECONDS', 30),
    ],

    'ai' => [
        // openai_compatible: any OpenAI-style /v1/chat/completions endpoint. anthropic: the Messages API.
        'openai_compatible' => [
            'base_url' => env('INTELLIGENCE_AI_BASE_URL'),
            'api_key' => env('INTELLIGENCE_AI_API_KEY'),
            'model' => env('INTELLIGENCE_AI_MODEL'),
        ],
        'anthropic' => [
            'base_url' => env('INTELLIGENCE_ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'api_key' => env('INTELLIGENCE_ANTHROPIC_API_KEY'),
            'model' => env('INTELLIGENCE_ANTHROPIC_MODEL'),
            'version' => env('INTELLIGENCE_ANTHROPIC_VERSION', '2023-06-01'),
            // Required by Anthropic when the key is an organization-level key rather than a workspace-scoped one.
            'workspace_id' => env('INTELLIGENCE_ANTHROPIC_WORKSPACE_ID'),
        ],
        // Circuit breaker: after this many consecutive failures the gateway stays open for `open_seconds`.
        'breaker_failures' => (int) env('INTELLIGENCE_AI_BREAKER_FAILURES', 3),
        'breaker_open_seconds' => (int) env('INTELLIGENCE_AI_BREAKER_OPEN_SECONDS', 300),
        // Hard caps whatever the settings say.
        'max_output_tokens' => (int) env('INTELLIGENCE_AI_MAX_OUTPUT_TOKENS', 1200),
        'daily_request_cap' => (int) env('INTELLIGENCE_AI_DAILY_REQUEST_CAP', 500),
        // Optional CA bundle for hosts whose PHP has no system certificate store (Windows); blank = PHP defaults.
        'ca_bundle' => env('INTELLIGENCE_AI_CA_BUNDLE'),
    ],

    'deployment' => [
        // Read by `intelligence:record-deployment` when no --release is given.
        'release' => env('APP_RELEASE'),
        'commit' => env('APP_COMMIT'),
    ],
];
