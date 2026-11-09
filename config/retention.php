<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Data retention (ADR 0004, docs/governance/retention-matrix.md)
|--------------------------------------------------------------------------
|
| Records (employee master, assignment and card history, cases, performance,
| transactions, documents, audit, verification and API logs) are kept until
| HR, Legal, Records Management, Audit and Data Protection approve a period
| for their class: no period is invented here. For each class:
|
|   RETENTION_<CLASS>_ACTION    retain | archive | purge_when_authorized
|   RETENTION_<CLASS>_DAYS      the approved period (audit logs: Security ›
|                               Audit Retention Days when this is empty)
|   RETENTION_<CLASS>_APPROVAL  reference of the signed approval
|
| `php artisan data:prune-retention` archives or purges a class only when all
| three are set and the class is one the application can process (the log
| classes). `php artisan data:retention-report` shows every class's state.
|
| Operational housekeeping below (notifications, reminders) is not a record
| and keeps its automatic windows.
|
*/

$records = [
    'employee_master', 'assignment_history', 'id_card_history', 'grievance_cases', 'discipline_cases',
    'appeal_and_tribunal_cases', 'performance_records', 'provider_transactions', 'uploaded_documents',
    'audit_logs', 'verification_logs', 'api_logs',
];

return [

    'records' => array_combine($records, array_map(fn (string $class): array => [
        'action' => env('RETENTION_'.strtoupper($class).'_ACTION', 'retain'),
        'days' => env('RETENTION_'.strtoupper($class).'_DAYS'),
        'approval' => env('RETENTION_'.strtoupper($class).'_APPROVAL'),
    ], $records)),

    // Where archived rows go (gzip NDJSON plus a manifest with checksums).
    'archive_disk' => env('RETENTION_ARCHIVE_DISK', 'local'),

    // Operational housekeeping, not records.
    'notifications_read_days' => (int) env('RETENTION_NOTIFICATIONS_READ_DAYS', 90),

    'notifications_days' => (int) env('RETENTION_NOTIFICATIONS_DAYS', 365),

    'daily_activity_reminders_days' => (int) env('RETENTION_DAILY_ACTIVITY_REMINDERS_DAYS', 90),

    // Rows processed per statement, so a large backlog never locks a table.
    'batch_size' => (int) env('RETENTION_BATCH_SIZE', 1000),

];
