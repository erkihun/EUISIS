<?php

return [
    // Off: the page reports NOT_CONFIGURED (never healthy). Production treats that as CRITICAL.
    'enabled' => (bool) env('BACKUP_STATUS_ENABLED', false),
    // pgbackrest: this host runs fixed read-only pgBackRest commands (co-located or client install).
    // report: this host only reads the report the ops runner publishes (no pgBackRest access here).
    'driver' => env('BACKUP_DRIVER', 'pgbackrest'),
    // Production rules (missing infrastructure is CRITICAL and a blocker) always apply in production;
    // enable here to rehearse them elsewhere, e.g. staging.
    'enforce_production_rules' => (bool) env('BACKUP_ENFORCE_PRODUCTION_RULES', false),
    // When true, broken WAL archiving is CRITICAL; otherwise it only degrades health to WARNING.
    'pitr_required' => (bool) env('BACKUP_PITR_REQUIRED', true),
    // Short cache for probe results so page renders do not start processes. 0 disables caching.
    'cache_seconds' => (int) env('BACKUP_STATUS_CACHE_SECONDS', 60),
    'pgbackrest' => [
        // Absolute paths; nothing here is taken from a request. Repository keys stay in pgBackRest's
        // own configuration. With run_as, PHP never reads that file: a sudoers rule lets the web
        // account run exactly these commands as the backup account (docs/runbooks/database-backup.md).
        'binary' => env('PGBACKREST_BINARY', '/usr/bin/pgbackrest'),
        'stanza' => env('PGBACKREST_STANZA', 'euisis'),
        'config' => env('PGBACKREST_CONFIG'),
        'run_as' => env('PGBACKREST_RUN_AS'),
        'sudo_binary' => env('PGBACKREST_SUDO_BINARY', '/usr/bin/sudo'),
        'timeout_seconds' => (int) env('PGBACKREST_TIMEOUT_SECONDS', 20),
        'check_timeout_seconds' => (int) env('PGBACKREST_CHECK_TIMEOUT_SECONDS', 120),
    ],
    // Ops-owned atomic JSON report; PHP has read access only. The report driver reads repository
    // state from it; both drivers read the operation journal, attestations and capacity from it.
    'report_path' => env('BACKUP_STATUS_PATH', '/var/lib/euisis-backup/status.json'),
    'report_stale_minutes' => (int) env('BACKUP_REPORT_STALE_MINUTES', 15),
    'stale_hours' => (int) env('BACKUP_STALE_HOURS', 30),
    'critical_hours' => (int) env('BACKUP_CRITICAL_HOURS', 48),
    'full_stale_days' => (int) env('BACKUP_FULL_STALE_DAYS', 8),
    'verify_stale_days' => (int) env('BACKUP_VERIFY_STALE_DAYS', 8),
    'restore_test_days' => (int) env('BACKUP_RESTORE_TEST_DAYS', 35),
    'wal_lag_seconds' => (int) env('BACKUP_WAL_LAG_SECONDS', 600),
    'wal_backlog_segments' => (int) env('BACKUP_WAL_BACKLOG_SEGMENTS', 16),
    'storage_warning_percent' => (int) env('BACKUP_STORAGE_WARNING_PERCENT', 80),
    'alert_repeat_minutes' => (int) env('BACKUP_ALERT_REPEAT_MINUTES', 360),
    // User IDs, checked again for backups.view_logs and backups.view_status when notifying.
    'alert_user_ids' => array_values(array_filter(explode(',', (string) env('BACKUP_ALERT_USER_IDS', '')))),
    'alert_mail' => env('BACKUP_ALERT_MAIL', false),
    'retention_full_count' => (int) env('BACKUP_RETENTION_FULL_COUNT', 12),
    // Supplementary encrypted pg_dump archive. It is never the primary recovery path, so a stale
    // or failed logical backup is a WARNING. Leave disabled until the runner's logical job is deployed.
    'logical_enabled' => (bool) env('BACKUP_LOGICAL_ENABLED', false),
    'logical_stale_days' => (int) env('BACKUP_LOGICAL_STALE_DAYS', 35),
    // Display labels only; repository hosts, paths and credentials stay in infrastructure config.
    'repository_names' => [
        1 => (string) env('BACKUP_REPOSITORY_1_NAME', 'Private repository'),
        2 => (string) env('BACKUP_REPOSITORY_2_NAME', 'Independent off-site repository'),
    ],
    'rpo' => env('BACKUP_RPO', 'NEEDS_DECISION'),
    'rto' => env('BACKUP_RTO', 'NEEDS_DECISION'),
];
