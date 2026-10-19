<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Telemetry;

/**
 * The metric names the collector writes and the rules and analyses read
 * (docs/intelligence-metrics-catalog.md, "System metrics"). A name is
 * `component.measure`; the dimension, where one exists, is a route group, a
 * queue name or an integration provider — never an identifier.
 */
final class Metrics
{
    // application (dimension: route group)
    public const REQUEST_TOTAL = 'request.total';

    public const REQUEST_2XX = 'request.s2xx';

    public const REQUEST_3XX = 'request.s3xx';

    public const REQUEST_4XX = 'request.s4xx';

    public const REQUEST_5XX = 'request.s5xx';

    public const REQUEST_401 = 'request.s401';

    public const REQUEST_403 = 'request.s403';

    public const REQUEST_429 = 'request.s429';

    public const REQUEST_SLOW = 'request.slow';

    public const REQUEST_LATENCY_AVG_MS = 'request.latency_avg_ms';

    public const REQUEST_LATENCY_P95_MS = 'request.latency_p95_ms';

    public const REQUEST_ERROR_RATE = 'request.error_rate_percent';

    public const SESSIONS_ACTIVE = 'sessions.active';

    // database
    public const DB_PING_MS = 'db.ping_ms';

    public const DB_CONNECTIONS_ACTIVE = 'db.connections_active';

    public const DB_CONNECTIONS_MAX = 'db.connections_max';

    public const DB_CONNECTIONS_PERCENT = 'db.connections_percent';

    public const DB_LONG_QUERIES = 'db.long_running_queries';

    public const DB_WAITING_LOCKS = 'db.waiting_locks';

    public const DB_DEADLOCKS_TOTAL = 'db.deadlocks_total';

    // cache / redis
    public const CACHE_PING_MS = 'cache.ping_ms';

    public const CACHE_AVAILABLE = 'cache.available';

    public const REDIS_PING_MS = 'redis.ping_ms';

    public const REDIS_AVAILABLE = 'redis.available';

    // queue (dimension: queue name)
    public const QUEUE_PENDING = 'queue.pending';

    public const QUEUE_RESERVED = 'queue.reserved';

    public const QUEUE_OLDEST_AGE_SECONDS = 'queue.oldest_age_seconds';

    public const QUEUE_FAILED_5M = 'queue.failed_5m';

    public const QUEUE_FAILED_TOTAL = 'queue.failed_total';

    // scheduler / storage / backups
    public const SCHEDULER_HEARTBEAT = 'scheduler.heartbeat';

    public const STORAGE_WRITABLE = 'storage.writable';

    public const STORAGE_FREE_PERCENT = 'storage.free_percent';

    public const BACKUP_HEALTH = 'backup.health';

    public const BACKUP_AGE_HOURS = 'backup.age_hours';

    // integrations (dimension: provider / service)
    public const INTEGRATION_CALLS = 'integration.calls';

    public const INTEGRATION_FAILURES = 'integration.failures';

    public const INTEGRATION_API_REQUESTS = 'integration.api_requests';

    public const INTEGRATION_API_FAILURES = 'integration.api_failures';

    public const INTEGRATION_BROADCAST_FAILURES_TOTAL = 'integration.broadcast_failures_total';

    // event integration layer (docs/integration/transactional-outbox.md)
    public const INTEGRATION_OUTBOX_PENDING = 'integration.outbox_pending';

    public const INTEGRATION_OUTBOX_OLDEST_SECONDS = 'integration.outbox_oldest_seconds';

    public const INTEGRATION_WEBHOOK_DEAD_TOTAL = 'integration.webhook_dead_total';

    // dimension: application code
    public const INTEGRATION_WEBHOOK_FAILURES = 'integration.webhook_failures';

    // security
    public const AUTH_MFA_FAILURES = 'auth.mfa_failures';

    public const AUTH_LOGIN_FAILURES = 'auth.login_failures';

    /** Health states encoded as a backup.health value. */
    public const HEALTH_VALUES = ['healthy' => 0, 'degraded' => 1, 'unhealthy' => 2, 'unknown' => 3];

    public const COMPONENT_APPLICATION = 'application';

    public const COMPONENT_DATABASE = 'database';

    public const COMPONENT_CACHE = 'cache';

    public const COMPONENT_REDIS = 'redis';

    public const COMPONENT_QUEUE = 'queue';

    public const COMPONENT_SCHEDULER = 'scheduler';

    public const COMPONENT_STORAGE = 'storage';

    public const COMPONENT_BACKUPS = 'backups';

    public const COMPONENT_INTEGRATIONS = 'integrations';

    public const COMPONENT_SECURITY = 'security';

    /** @return list<string> */
    public static function components(): array
    {
        return [
            self::COMPONENT_APPLICATION, self::COMPONENT_DATABASE, self::COMPONENT_CACHE, self::COMPONENT_REDIS, self::COMPONENT_QUEUE,
            self::COMPONENT_SCHEDULER, self::COMPONENT_STORAGE, self::COMPONENT_BACKUPS, self::COMPONENT_INTEGRATIONS, self::COMPONENT_SECURITY,
        ];
    }
}
