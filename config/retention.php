<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Data retention (risk register DB-9 decision, 2026-10-11)
|--------------------------------------------------------------------------
|
| `php artisan data:prune-retention` deletes log rows older than these
| windows, daily, on one server. Audit logs and security events follow the
| admin setting Security → Audit Retention Days (default 730), not this file.
|
| Never pruned here: cafeteria and service transactions, ledgers and
| settlements (financial records), employee and assignment history,
| documents, and grievance or discipline case records (they have their own
| retention and legal hold). Audit rows about a grievance are kept until the
| case is archived and off legal hold. API request logs keep their own job
| (api:prune-logs, 90 days).
|
*/

return [

    'notifications_read_days' => (int) env('RETENTION_NOTIFICATIONS_READ_DAYS', 90),

    'notifications_days' => (int) env('RETENTION_NOTIFICATIONS_DAYS', 365),

    'verification_logs_days' => (int) env('RETENTION_VERIFICATION_LOGS_DAYS', 365),

    'daily_activity_reminders_days' => (int) env('RETENTION_DAILY_ACTIVITY_REMINDERS_DAYS', 90),

    // Rows deleted per statement, so a large backlog never locks a table.
    'batch_size' => (int) env('RETENTION_BATCH_SIZE', 1000),

];
