<?php

declare(strict_types=1);

/*
 * Event integration layer (docs/integration/transactional-outbox.md).
 *
 * Rollout switches, in order:
 *   events.enabled          write domain changes to the outbox (shadow mode
 *                           when the other two are off)
 *   change_feed.enabled     partners may read GET /api/v1/changes
 *   webhooks.enabled        signed push deliveries to subscriptions
 *
 * Retention is deliberately null: no policy has been decided
 * (NEEDS_POLICY_DECISION_EVENT_RETENTION), so nothing is purged.
 */
return [
    'events' => [
        'enabled' => (bool) env('INTEGRATION_EVENTS_ENABLED', true),
        // Rows the publisher claims per run.
        'publish_batch' => (int) env('INTEGRATION_PUBLISH_BATCH', 500),
        // Upper bound on a serialized event payload (bytes).
        'max_payload_bytes' => (int) env('INTEGRATION_MAX_PAYLOAD_BYTES', 16384),
        // Event types not recorded at all, comma separated (e.g. "position.occupied,position.vacated").
        // The rollback switch for an event type without a deployment.
        'disabled_types' => array_values(array_filter(array_map('trim', explode(',', (string) env('INTEGRATION_DISABLED_EVENT_TYPES', ''))))),
    ],

    'change_feed' => [
        'enabled' => (bool) env('INTEGRATION_CHANGE_FEED_ENABLED', false),
        'default_limit' => 100,
        'max_limit' => 500,
        // Most UUIDs one reconciliation call may ask about.
        'reconcile_max' => 100,
    ],

    'webhooks' => [
        'enabled' => (bool) env('INTEGRATION_WEBHOOKS_ENABLED', false),
        'queue' => env('INTEGRATION_QUEUE', 'integrations'),
        // Retry policy: bounded exponential backoff with full jitter.
        'max_attempts' => (int) env('INTEGRATION_WEBHOOK_MAX_ATTEMPTS', 8),
        'base_delay_seconds' => (int) env('INTEGRATION_WEBHOOK_BASE_DELAY', 30),
        'max_delay_seconds' => (int) env('INTEGRATION_WEBHOOK_MAX_DELAY', 3600),
        'connect_timeout_seconds' => 3,
        'timeout_seconds' => 10,
        // Deliveries claimed per run, and per subscription per run (fairness).
        'batch' => (int) env('INTEGRATION_WEBHOOK_BATCH', 200),
        'per_subscription_batch' => (int) env('INTEGRATION_WEBHOOK_PER_SUBSCRIPTION_BATCH', 50),
        // A claimed delivery whose worker died becomes due again after this.
        'lease_seconds' => 120,
        // Replay-protection window partners should enforce, and the overlap
        // during which the previous secret still signs after a rotation.
        'signature_tolerance_seconds' => 300,
        'rotation_overlap_hours' => (int) env('INTEGRATION_WEBHOOK_ROTATION_OVERLAP_HOURS', 24),
        // Consecutive failures before a subscription shows as degraded.
        'degraded_after_failures' => 3,
        // Callback URL policy (SSRF). Plain http only where explicitly allowed
        // (local development); private/loopback/link-local/metadata targets
        // are refused unless the host is on the internal allowlist.
        'allow_http' => (bool) env('INTEGRATION_WEBHOOK_ALLOW_HTTP', false),
        'allowed_internal_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('INTEGRATION_WEBHOOK_INTERNAL_HOSTS', ''))))),
        // Most replayed events one replay request may enqueue.
        'replay_max_events' => 5000,
    ],

    'retention' => [
        // Days to keep published events and finished deliveries. null = keep
        // everything (NEEDS_POLICY_DECISION_EVENT_RETENTION).
        'events_days' => env('INTEGRATION_EVENT_RETENTION_DAYS') !== null ? (int) env('INTEGRATION_EVENT_RETENTION_DAYS') : null,
        'deliveries_days' => env('INTEGRATION_DELIVERY_RETENTION_DAYS') !== null ? (int) env('INTEGRATION_DELIVERY_RETENTION_DAYS') : null,
    ],
];
