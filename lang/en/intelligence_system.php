<?php

declare(strict_types=1);

/*
 * Addis One Intelligence — technical domain dictionary (merged under the
 * `intelligence.` prefix by IntelligenceLabels). Rules, recommendations,
 * evidence row labels, components, incident and health vocabulary.
 * Wording: an incident is a measured condition, a recommendation is advice
 * a person weighs; a correlation with a release is never called a cause.
 */
return [
    'components' => [
        'application' => 'Application', 'database' => 'Database', 'cache' => 'Cache', 'redis' => 'Redis', 'queue' => 'Queues',
        'scheduler' => 'Scheduler', 'storage' => 'Storage', 'backups' => 'Backups', 'integrations' => 'Integrations', 'security' => 'Security signals',
    ],

    'status' => [
        'open' => 'Open', 'investigating' => 'Investigating', 'mitigated' => 'Mitigated', 'resolved' => 'Resolved', 'closed' => 'Closed',
    ],

    'verification' => ['pending' => 'Verification pending', 'verified' => 'Verified', 'failed' => 'Verification failed'],

    'events' => [
        'detected' => 'Detected', 'recurred' => 'Recurred', 'escalated' => 'Escalated', 'status_changed' => 'Status changed', 'owner_changed' => 'Owner changed',
        'recommendation_created' => 'Recommendations created', 'ai_explained' => 'AI explanation recorded', 'mitigated' => 'Mitigated', 'resolved' => 'Resolved',
        'verified' => 'Verified', 'closed' => 'Closed', 'note' => 'Note',
    ],

    'rules' => [
        'http_error_rate' => ['title' => 'Server errors on :group', 'impact' => 'Requests to :group are failing with server errors; people on this flow see error pages or lost submissions.'],
        'http_latency' => ['title' => 'Slow responses on :group', 'impact' => 'Pages of :group answer slowly; people wait or abandon the flow.'],
        'queue_backlog' => ['title' => 'Queue backlog on :queue', 'impact' => 'Background work on :queue is waiting; notifications, exports and reminders are late.'],
        'failed_jobs' => ['title' => 'Failed background jobs', 'impact' => 'Background jobs are failing; some notifications, exports or syncs did not happen.'],
        'error_repeat' => ['title' => 'Repeated error: :exception', 'impact' => 'The same failure keeps happening on :group; whoever triggers it sees an error page.'],
        'db_latency' => ['title' => 'Database responding slowly', 'impact' => 'Every page depends on the database; slowness here slows the whole platform.'],
        'db_connections' => ['title' => 'Database connections near the limit', 'impact' => 'When connections run out, new requests fail outright.'],
        'cache_unavailable' => ['title' => 'Cache store :store unavailable or slow', 'impact' => 'Sessions, settings and rate limits depend on the cache; sign-ins and pages may fail.'],
        'redis_unavailable' => ['title' => 'Redis unavailable', 'impact' => 'Queues, cache or sessions configured on Redis stop working.'],
        'scheduler_missed' => ['title' => 'Scheduler stopped (:minutes min without a heartbeat)', 'impact' => 'Reminders, SLA checks, backups and this telemetry do not run until the scheduler is back.'],
        'storage' => ['title' => 'Storage at risk (:free% free)', 'impact' => 'Uploads, exports, PDFs and logs fail when the disk is full.'],
        'backup' => ['title' => 'Backup health degraded', 'impact' => 'The recovery point is at risk; a restore may not be possible from a recent state.'],
        'integration_failure' => ['title' => 'Integration :provider failing', 'impact' => 'Messages through :provider are not being delivered.'],
        'auth_failures' => ['title' => 'Unusual volume of denied or throttled requests', 'impact' => 'A spike in failed sign-ins, denied access or throttling may be a misconfiguration or an attempt; it names nobody.'],
    ],

    'recommendations' => [
        'http_error_rate' => [
            'immediate' => ['title' => 'Confirm the failing route and recent change', 'body' => 'Open Error & Failure Analysis for this route group, note the top fingerprint and whether a deployment landed shortly before. If a feature flag isolates the flow, consider pausing it.'],
            'short_term' => ['title' => 'Fix the top error signature', 'body' => 'Take the most frequent fingerprint, reproduce it with the error id from the log, and fix the code path or data it trips on.'],
            'permanent' => ['title' => 'Add a regression test and a guard', 'body' => 'Cover the failing input with a test; validate the input at the FormRequest so the same data cannot reach the failing code again.'],
            'verification' => ['title' => 'Watch the error rate return to baseline', 'body' => 'After the fix, the five-minute 5xx rate for this group should return under its baseline and the incident should mitigate on its own.'],
        ],
        'http_latency' => [
            'immediate' => ['title' => 'Check database and queue health first', 'body' => 'Latency on one group usually follows a slow query or a saturated database; open Database Health and Queue Health before touching code.'],
            'short_term' => ['title' => 'Find the slow query or N+1', 'body' => 'Inspect the execution plan of the group\'s heaviest query, check for a missing index or an unbounded list, and add eager loading where rows are loaded in a loop.'],
            'permanent' => ['title' => 'Index or restructure after measurement', 'body' => 'Add an index only after EXPLAIN shows a scan; large tables take CONCURRENTLY migrations outside peak hours.'],
            'verification' => ['title' => 'Compare p95 before and after', 'body' => 'The p95 for this group should fall back to its baseline on the Application Performance chart.'],
        ],
        'queue_backlog' => [
            'immediate' => ['title' => 'Check that a worker consumes this queue', 'body' => 'Confirm a queue worker process runs for this queue name (notifications and long need their own workers); a worker on default alone leaves the others waiting.'],
            'short_term' => ['title' => 'Add capacity or clear a stuck job', 'body' => 'If workers are running, add a process or raise --timeout for long jobs; a job that never finishes blocks the queue and should be fixed or removed by a person.'],
            'permanent' => ['title' => 'Right-size workers by queue', 'body' => 'Separate heavy exports from notifications permanently and monitor the oldest-job age per queue.'],
            'verification' => ['title' => 'Pending count and oldest age fall', 'body' => 'Queue Health should show pending jobs and the oldest waiting job returning to their baseline.'],
        ],
        'failed_jobs' => [
            'immediate' => ['title' => 'Read the failure reasons', 'body' => 'Run queue:failed and read the exception of the most recent failures; group them by job class.'],
            'short_term' => ['title' => 'Fix the cause, then retry', 'body' => 'Fix the configuration or code the job trips on, then queue:retry the affected ids; do not retry blindly.'],
            'verification' => ['title' => 'No new failures in the next hour', 'body' => 'The failed-jobs count per hour should return to its baseline.'],
        ],
        'error_repeat' => [
            'immediate' => ['title' => 'Look up the error id', 'body' => 'Search the application log for the last error id of this fingerprint; the log holds the full context this page deliberately does not.'],
            'short_term' => ['title' => 'Fix the code path', 'body' => 'The sample context names the first application frame; start there. If a deployment is linked, compare with the previous release.'],
            'permanent' => ['title' => 'Prevent the input or state', 'body' => 'Add validation or a guard upstream and a test that reproduces the failure.'],
            'verification' => ['title' => 'The fingerprint stops recurring', 'body' => 'Error & Failure Analysis should show no new occurrence; the incident mitigates after three quiet runs.'],
        ],
        'db_latency' => [
            'immediate' => ['title' => 'Check load and long-running queries', 'body' => 'Open Database Health: connection usage and long-running queries; a report or an export running in business hours is the usual cause.'],
            'short_term' => ['title' => 'Tune or move the heavy query', 'body' => 'EXPLAIN the long-running statement; add the missing index after measurement or move the work to a queued job.'],
            'permanent' => ['title' => 'Capacity or read replica', 'body' => 'If load is structural, plan capacity (NEEDS_INFRASTRUCTURE_READ_REPLICA) rather than tuning around it.'],
            'verification' => ['title' => 'Probe time back to baseline', 'body' => 'The database ping on Database Health should return to its baseline.'],
        ],
        'db_connections' => [
            'immediate' => ['title' => 'Find who holds the connections', 'body' => 'Check pg_stat_activity (or the process list) for idle-in-transaction sessions and long queries; too many workers or web processes also exhaust the pool.'],
            'short_term' => ['title' => 'Reduce concurrent connections', 'body' => 'Lower worker or FPM process counts to what the database allows, or put PgBouncer in front.'],
            'permanent' => ['title' => 'Connection pooling', 'body' => 'Adopt a pooler sized to max_connections and keep web, workers and schedulers within it.'],
            'verification' => ['title' => 'Connection usage under the limit', 'body' => 'Database Health should show usage well under the high-water mark.'],
        ],
        'cache_unavailable' => [
            'immediate' => ['title' => 'Check the cache store', 'body' => 'For the database store, check the database; for Redis, check the service and network. Sessions and rate limits depend on it.'],
            'short_term' => ['title' => 'Restore or fail over the store', 'body' => 'Bring the store back or point CACHE_STORE at a healthy one and clear stale entries; a person restarts services, never this page.'],
            'verification' => ['title' => 'Cache probe succeeds', 'body' => 'System Health should show the cache available with a normal ping.'],
        ],
        'redis_unavailable' => [
            'immediate' => ['title' => 'Check the Redis service', 'body' => 'Confirm the service, port and credentials; queue workers and sessions on Redis are down meanwhile.'],
            'short_term' => ['title' => 'Restore Redis or switch drivers', 'body' => 'Restore the service; if it cannot be restored quickly, switch queue, cache and session drivers to database and restart workers.'],
            'verification' => ['title' => 'Redis ping succeeds', 'body' => 'System Health should show Redis available.'],
        ],
        'scheduler_missed' => [
            'immediate' => ['title' => 'Check the cron entry and lock', 'body' => 'Confirm `schedule:run` is still in cron (or the systemd timer) on the server that owns the schedule, and that a stale lock is not blocking it.'],
            'short_term' => ['title' => 'Run the overdue tasks', 'body' => 'Once the scheduler runs again, let it catch up; reminders and SLA checks are idempotent.'],
            'verification' => ['title' => 'Heartbeat every five minutes', 'body' => 'System Health should show the scheduler heartbeat current again.'],
        ],
        'storage' => [
            'immediate' => ['title' => 'Free space safely', 'body' => 'Prune old logs and temporary exports per retention; do not delete uploads or backups.'],
            'short_term' => ['title' => 'Grow the volume', 'body' => 'Extend the disk or move uploads to object storage; a high-risk change a person plans.'],
            'verification' => ['title' => 'Free space above the low mark', 'body' => 'System Health should show storage writable with free space above 10%.'],
        ],
        'backup' => [
            'immediate' => ['title' => 'Open Backup & Recovery', 'body' => 'The backup module states the exact reason code; follow its runbook.'],
            'short_term' => ['title' => 'Restore the recovery point', 'body' => 'Fix the failing backup or verification and confirm a fresh verified backup exists.'],
            'verification' => ['title' => 'Backup health healthy', 'body' => 'Backup & Recovery should report healthy and the incident mitigates.'],
        ],
        'integration_failure' => [
            'immediate' => ['title' => 'Check the provider status and configuration', 'body' => 'Confirm the provider is up, the credential is valid and the budget cap is not exhausted (External usage).'],
            'short_term' => ['title' => 'Repair and resend', 'body' => 'Fix the configuration and let the queued notifications retry; nothing is resent automatically from here.'],
            'verification' => ['title' => 'Calls succeed again', 'body' => 'Integration Health should show successful calls for the provider.'],
        ],
        'auth_failures' => [
            'immediate' => ['title' => 'Check for a misconfiguration first', 'body' => 'A changed permission, an expired session policy or a broken client often explains a spike; look at which route group carries the 401/403.'],
            'short_term' => ['title' => 'Hand suspicious patterns to security', 'body' => 'If the pattern looks like an attempt rather than a misconfiguration, open a formal security review with the audit log; this page accuses nobody.'],
            'verification' => ['title' => 'Counts back to baseline', 'body' => 'Denied and throttled requests should return to their baseline on System Health.'],
        ],
    ],

    'runbooks' => [
        'queue_backlog' => [
            'title' => 'Queue backlog investigation',
            'summary' => 'Jobs are waiting or failing on one queue. Find out whether a worker is missing, stuck or undersized before changing anything.',
            'steps' => [
                1 => 'Open Redis & Queue Health and note pending jobs, the oldest waiting job and failed jobs per queue.',
                2 => 'On the application server, list the queue worker processes and confirm one consumes the affected queue name (default, notifications and long each need a worker).',
                3 => 'If a worker is running, read queue:failed for the newest failures and group them by job class; a job that times out repeatedly blocks the queue.',
                4 => 'Fix the configuration or code the failing job needs; retry only the affected ids with queue:retry.',
                5 => 'If the backlog is pure volume, add a worker process or raise --timeout for the long queue; do not raise --tries blindly.',
                6 => 'Verify on Redis & Queue Health that pending and oldest-age return to their baseline; the incident mitigates after three quiet runs.',
            ],
        ],
        'database_pressure' => [
            'title' => 'PostgreSQL connection or latency pressure',
            'summary' => 'The database answers slowly or its connections are near the limit. Look for the heavy work first; never restart the database from here.',
            'steps' => [
                1 => 'Open Database Health: probe time, connections used, long-running queries and waiting locks.',
                2 => 'On the database, list active sessions (pg_stat_activity) ordered by duration; note idle-in-transaction sessions and any query older than 30 seconds.',
                3 => 'Identify the source of long queries: a report export in business hours, a missing index, an unbounded list. EXPLAIN (ANALYZE, BUFFERS) the statement on a copy, never on production data without a LIMIT.',
                4 => 'If connections are the issue, count web, worker and scheduler processes against max_connections and reduce the surplus or introduce PgBouncer (a planned change).',
                5 => 'Add an index only after the plan shows a sequential scan on a large table; create it CONCURRENTLY outside peak hours.',
                6 => 'Verify the probe time and connection usage return to their baseline on Database Health.',
            ],
        ],
        'redis_unavailable' => [
            'title' => 'Redis or cache store unavailable',
            'summary' => 'Sessions, rate limits, queues or cache depend on a store that does not answer.',
            'steps' => [
                1 => 'Confirm which store is affected on System Health (cache available / Redis available) and which drivers use it (cache, session, queue, broadcast).',
                2 => 'Check the service, port, credentials and memory on the Redis host; check the database if the cache store is the database.',
                3 => 'Restore the service. If it cannot be restored quickly, switch the affected drivers to the database store in the environment, clear config cache, restart workers — a person does this, not the web app.',
                4 => 'Expect signed-in users to be signed out when sessions lived in the lost store; announce it.',
                5 => 'Verify the probes succeed on System Health and that queue workers consume again.',
            ],
        ],
        'integration_failure' => [
            'title' => 'External integration failing',
            'summary' => 'E-mail, SMS or an external API call fails or times out.',
            'steps' => [
                1 => 'Open Integration Health and note the provider, calls, failures and the last successful call.',
                2 => 'Check the provider\'s own status page and the credential\'s validity; check the spend cap on External usage (a refused call is not a provider failure).',
                3 => 'Send one test through System Settings (test e-mail / test SMS) and read the error it returns.',
                4 => 'Fix the configuration; queued notifications retry on their own schedule. Nothing is resent from this page.',
                5 => 'Verify successful calls appear again on Integration Health.',
            ],
        ],
        'storage_full' => [
            'title' => 'Storage full or not writable',
            'summary' => 'Uploads, exports, PDFs and logs fail when the disk is full or the permission is wrong.',
            'steps' => [
                1 => 'Open System Health: storage writable and free space.',
                2 => 'Check disk usage per directory under storage/ and the log directory; the usual culprits are old daily logs and temporary exports.',
                3 => 'Prune only what retention allows (logs beyond LOG_DAILY_DAYS, temporary exports). Never delete uploads, backups or the sessions table.',
                4 => 'If the volume is simply too small, plan a resize or a move of uploads to object storage — a high-risk change outside this page.',
                5 => 'Verify free space above 10% and storage writable on System Health.',
            ],
        ],
        'deployment_regression' => [
            'title' => 'Regression after a deployment',
            'summary' => 'Errors or latency rose shortly after a release. Correlation is a strong hint, not proof; confirm before rolling back.',
            'steps' => [
                1 => 'Open the incident and read the linked deployment (release, commit, minutes before detection) and the top error fingerprints.',
                2 => 'Open Error & Failure Analysis: are the fingerprints new since the release, or older ones that grew?',
                3 => 'Compare the changed files of the release with the fingerprint\'s first application frame and route group.',
                4 => 'Reproduce with the error id from the log on a staging copy; if a feature flag covers the flow, pause the flow rather than the whole release.',
                5 => 'Rolling back is a human decision executed with the release runbook (docs/runbook/deployment-and-rollback.md); nothing rolls back from here.',
                6 => 'Verify error rate and latency for the route group return to baseline after the fix or rollback.',
            ],
        ],
        'pdf_generation_failure' => [
            'title' => 'PDF generation failing',
            'summary' => 'ID cards, job descriptions or reports fail to render.',
            'steps' => [
                1 => 'Find the fingerprint on Error & Failure Analysis (dompdf, GD or font errors) and its route group.',
                2 => 'Check the PHP extensions on the server (gd, mbstring) and the font files the renderer needs; a missing Ethiopic font breaks Amharic PDFs.',
                3 => 'Check storage: a full disk or an unwritable temp directory fails every render.',
                4 => 'Render one document from staging with the same template to reproduce; fix the template or the data it trips on.',
                5 => 'Verify the fingerprint stops recurring.',
            ],
        ],
        'backup_failure' => [
            'title' => 'Backup health degraded',
            'summary' => 'The backup module reports a warning or a failure. Its own runbooks are authoritative.',
            'steps' => [
                1 => 'Open Backup & Recovery and read the reason code and message.',
                2 => 'Follow docs/runbooks/backup-health-troubleshooting.md for that reason code.',
                3 => 'Do not consider the system safe because a job ran: a verified restore test is the only proof of a recovery point.',
                4 => 'Verify Backup & Recovery reports healthy; the incident mitigates on its own.',
            ],
        ],
        'scheduler_stopped' => [
            'title' => 'Scheduler stopped',
            'summary' => 'No heartbeat for fifteen minutes: reminders, SLA checks, backups and telemetry are not running.',
            'steps' => [
                1 => 'On the server that owns the schedule, confirm the cron entry (or systemd timer) for schedule:run is present and the user can run php.',
                2 => 'Run schedule:run once by hand and read its output for the failing task; a stale onOneServer lock in the cache can also block it.',
                3 => 'Fix the entry or clear the stale lock; the overdue tasks catch up on their own and are idempotent.',
                4 => 'Verify the heartbeat is current on System Health.',
            ],
        ],
        'auth_failure_spike' => [
            'title' => 'Spike of denied, throttled or failed MFA requests',
            'summary' => 'An unusual volume of 401/403/429 or failed MFA challenges. It may be a misconfiguration or an attempt; this page names nobody.',
            'steps' => [
                1 => 'Open System Health and note which route group carries the denied requests and whether MFA failures dominate.',
                2 => 'Check recent permission or role changes and the session policy: a changed permission or an expired policy produces a burst of 403 from legitimate users.',
                3 => 'If the pattern points at one address or account, hand it to the security officer with the audit log; formal incident response stays with them.',
                4 => 'Verify the counters return to baseline.',
            ],
        ],
    ],

    'panels' => [
        'system_headline' => ['title' => 'System health at a glance', 'description' => 'Active incidents by severity and how many components are unhealthy, degraded or not measured.'],
        'component_states' => ['title' => 'Components', 'description' => 'The freshest sample of each component, raised by any active incident. Not measured means unknown, never healthy.'],
        'error_rate_trend' => ['title' => 'Server-error rate', 'description' => 'Share of requests that ended in a server error, all route groups together.'],
        'failures_by_group' => ['title' => 'Server errors by route group', 'description' => 'Requests that ended in a server error, per route group.'],
        'error_headline' => ['title' => 'Errors at a glance', 'description' => 'Error signatures seen in the period, their occurrences, new signatures and the incidents they opened.'],
        'top_fingerprints' => ['title' => 'Most frequent error signatures', 'description' => 'Exception class, normalised message, route group and first application area. Never arguments, input or a person.'],
        'fingerprints_by_group' => ['title' => 'Error occurrences by route group', 'description' => 'Occurrences of all signatures per route group.'],
        'app_headline' => ['title' => 'Application performance at a glance', 'description' => 'Requests, server-error rate, worst p95 and slow responses in the period. Totals are sampled one in twenty on a database cache store.'],
        'error_rate_by_group' => ['title' => 'Error rate by route group', 'description' => 'Server-error share per bucket for the five busiest route groups.'],
        'latency_trend' => ['title' => 'p95 latency by route group', 'description' => 'Highest five-minute p95 per bucket for the five busiest route groups.'],
        'requests_by_group' => ['title' => 'Requests by route group', 'description' => 'Request volume per route group in the period.'],
        'p95_by_group' => ['title' => 'Worst p95 by route group', 'description' => 'The highest p95 observed per route group in the period.'],
        'db_headline' => ['title' => 'Database at a glance', 'description' => 'Latest probe time, connection usage, long-running queries, waiting locks and deadlocks in the period. Statements are never read.'],
        'db_ping_trend' => ['title' => 'Database probe time', 'description' => 'Average time of a trivial query per bucket.'],
        'db_connections_trend' => ['title' => 'Connections used', 'description' => 'Highest share of max_connections per bucket.'],
        'db_long_queries_trend' => ['title' => 'Long-running queries', 'description' => 'Highest count of queries running longer than the configured seconds per bucket.'],
        'queue_headline' => ['title' => 'Queues at a glance', 'description' => 'Pending jobs across queues, the oldest waiting job, and failed jobs in the period and in total.'],
        'cache_headline' => ['title' => 'Cache and Redis', 'description' => 'Availability and probe time of the cache store, and of Redis where it is configured.'],
        'queue_pending_trend' => ['title' => 'Pending jobs by queue', 'description' => 'Highest pending count per bucket, per queue.'],
        'queue_failed_trend' => ['title' => 'Failed jobs', 'description' => 'Jobs that failed per bucket.'],
        'integration_headline' => ['title' => 'Integrations at a glance', 'description' => 'Outbound calls and failures (SMS and other budgeted providers), external API requests and failures, broadcast failures.'],
        'integrations_table' => ['title' => 'Providers', 'description' => 'Per provider: calls, failures, failure rate and the last bucket with a success.'],
        'api_requests_trend' => ['title' => 'External API requests', 'description' => 'Requests from external applications and their failures per bucket.'],
        'email_delivery' => ['title' => 'E-mail delivery', 'description' => 'Per-message e-mail outcomes are not recorded by the platform; only the test send is audited.'],
        'deployment_headline' => ['title' => 'Deployments at a glance', 'description' => 'Releases recorded in the period and how many had an incident start within an hour.'],
        'deployments_table' => ['title' => 'Recorded releases', 'description' => 'Release, commit, environment, time, and incidents and new error signatures within the hour after. A correlation, not a cause.'],
        'predictive_headline' => ['title' => 'Trend watch at a glance', 'description' => 'Series watched for a sustained rise, how many are deteriorating, how many have too little history to judge.'],
        'trend_watch' => ['title' => 'Watched series', 'description' => 'For each series: window, points, first and last value, change and the verdict with its confidence.'],
    ],

    'kpi' => [
        'incidents_critical' => 'Critical incidents', 'incidents_high' => 'High incidents', 'incidents_medium' => 'Medium incidents',
        'components_unhealthy' => 'Unhealthy components', 'components_degraded' => 'Degraded components', 'components_unknown' => 'Not measured',
        'fingerprints_active' => 'Error signatures', 'occurrences_recent' => 'Occurrences', 'new_signatures' => 'New signatures', 'incidents_from_errors' => 'Error incidents',
        'requests_total' => 'Requests', 'error_rate' => 'Server-error rate', 'p95_max' => 'Worst p95', 'slow_requests' => 'Slow responses',
        'db_ping' => 'Probe time', 'db_connections_percent' => 'Connections used', 'db_long_queries' => 'Long-running queries', 'db_waiting_locks' => 'Waiting locks', 'db_deadlocks_delta' => 'Deadlocks in period',
        'queue_pending' => 'Pending jobs', 'queue_oldest_minutes' => 'Oldest waiting (min)', 'queue_failed_window' => 'Failed in period', 'queue_failed_total' => 'Failed total',
        'cache_state' => 'Cache available', 'cache_ping' => 'Cache probe', 'redis_state' => 'Redis available', 'redis_ping' => 'Redis probe',
        'integration_calls' => 'Provider calls', 'integration_failures' => 'Provider failures', 'api_requests' => 'External API requests', 'api_failures' => 'External API failures', 'broadcast_failures' => 'Broadcast failures (total)',
        'deployments_count' => 'Releases', 'deployments_with_incidents' => 'Releases with an incident', 'active_correlated_incidents' => 'Active incidents linked to a release',
        'series_watched' => 'Series watched', 'series_deteriorating' => 'Deteriorating', 'series_insufficient' => 'Too little history',
    ],

    'kpiHint' => [
        'components_unknown' => 'A component with no sample in the last fifteen minutes, or one this installation does not expose (Redis, connection statistics on SQLite).',
        'requests_total' => 'On a database or file cache store totals are sampled one request in twenty and scaled; failures are always exact.',
        'p95_max' => 'Approximated from latency buckets (100, 300, 1000, 3000 ms).',
        'db_deadlocks_delta' => 'Difference between the last and first cumulative deadlock count in the period (PostgreSQL only).',
        'cache_state' => '1 = the probe wrote and read a key; 0 = it did not.',
    ],

    'insights' => [
        'open_incident' => ['title' => 'Open incident', 'summary' => 'Incident :reference is active.', 'recommendation' => 'Open incident :reference, follow its recommendations and runbook, and record what was done.'],
        'latency_deteriorating' => ['title' => 'Latency on :group is deteriorating', 'summary' => 'The hourly p95 for :group rose from :first ms to :last ms (+:percent%) across :points points in the last :window, and may cause service degradation if the trend continues.', 'recommendation' => 'Open Database Health and Queue Health first; then inspect the heaviest query of this route group before load grows.'],
        'db_ping_deteriorating' => ['title' => 'Database probe time is deteriorating', 'summary' => 'The hourly average probe rose from :first ms to :last ms (+:percent%) across :points points in the last :window.', 'recommendation' => 'Check long-running queries and connection usage now; plan capacity if the rise is structural.'],
        'db_connections_deteriorating' => ['title' => 'Database connection usage is rising', 'summary' => 'Connections used rose from :first% to :last% of the maximum (+:percent%) across :points points in the last :window.', 'recommendation' => 'Count web, worker and scheduler processes against max_connections and reduce or pool before the limit is hit.'],
        'queue_growing' => ['title' => 'Queue :queue is growing', 'summary' => 'Pending jobs on :queue rose from :first to :last (+:percent%) across :points points in the last :window.', 'recommendation' => 'Confirm a worker consumes :queue and that no job is stuck; add capacity if the volume is genuine.'],
        'trend_deteriorating' => ['title' => ':series is deteriorating', 'summary' => ':series rose from :first to :last (+:percent%) across :points points in the last :window, and may cause degradation if the trend continues.', 'recommendation' => 'Inspect the component behind this series before the trend reaches a limit; the verdict is a trend, not a prediction of failure.'],
        'deployment_correlated' => ['title' => 'Incident :reference began after release :release', 'summary' => 'The incident was detected :minutes minutes after release :release was recorded. A strong correlation, not proof of causation.', 'recommendation' => 'Compare the release\'s changes with the incident\'s error signatures; rolling back is a human decision made with the release runbook.'],
    ],

    'columns' => [
        'component' => 'Component', 'state' => 'State', 'active_incidents' => 'Active incidents', 'detail' => 'Latest sample', 'sampled_at' => 'Sampled',
        'exception' => 'Exception', 'message' => 'Message (normalised)', 'route_group' => 'Route group', 'occurrences' => 'Occurrences', 'first_seen' => 'First seen', 'last_seen' => 'Last seen',
        'provider' => 'Provider', 'calls' => 'Calls', 'failures' => 'Failures', 'failure_rate' => 'Failure rate', 'last_success' => 'Last success',
        'release' => 'Release', 'commit' => 'Commit', 'environment' => 'Environment', 'deployed_at' => 'Deployed', 'incidents_after' => 'Incidents within 1 h', 'new_errors_after' => 'New error signatures within 1 h',
        'series' => 'Series', 'window' => 'Window', 'points' => 'Points', 'first_value' => 'First', 'last_value' => 'Last', 'change' => 'Change', 'verdict' => 'Verdict', 'confidence' => 'Confidence',
    ],

    'series' => ['all' => 'All route groups', 'failures' => 'Server errors', 'requests' => 'Requests', 'p95' => 'p95 (ms)', 'occurrences' => 'Occurrences'],

    'values' => [
        'route_group' => [
            'public_feedback' => 'Public feedback', 'daily_activity' => 'Daily activity', 'cafeteria' => 'Cafeteria', 'performance' => 'Performance', 'field_work' => 'Field work',
            'transfers' => 'Transfers', 'employee_portal' => 'Employee portal', 'auth' => 'Sign-in', 'api' => 'External API', 'admin' => 'Administration', 'other' => 'Other', '__other' => 'Other',
        ],
        'series_name' => ['requests' => 'Requests', 'failures' => 'Failures'],
        'trend_verdict' => ['stable' => 'Stable', 'deteriorating' => 'Deteriorating', 'insufficient' => 'Too little history'],
    ],

    'risk' => ['safe_check' => 'Safe check', 'potential_fix' => 'Potential fix', 'high_risk' => 'High-risk change'],

    'category' => ['immediate' => 'Immediate mitigation', 'short_term' => 'Short-term fix', 'permanent' => 'Permanent fix', 'verification' => 'Verification', 'management' => 'Management action'],

    'review' => ['pending' => 'Pending review', 'useful' => 'Useful', 'not_useful' => 'Not useful', 'accepted' => 'Accepted', 'rejected' => 'Rejected', 'incorrect_diagnosis' => 'Incorrect diagnosis'],

    'evidence' => [
        'requests' => 'Requests', 'failures' => 'Failures', 'rate_percent' => 'Error rate (%)', 'baseline_median_percent' => 'Baseline median (%)',
        'baseline_samples' => 'Baseline samples', 'limit_percent' => 'Configured limit (%)', 'p95_ms' => 'p95 latency (ms)', 'baseline_median_ms' => 'Baseline median (ms)',
        'limit_ms' => 'Configured limit (ms)', 'pending' => 'Pending jobs', 'oldest_minutes' => 'Oldest waiting job (min)', 'baseline_median' => 'Baseline median',
        'limit_jobs' => 'Configured limit (jobs)', 'limit_age_minutes' => 'Configured age limit (min)', 'failed_last_hour' => 'Failed in the last hour', 'failed_last_5m' => 'Failed in the last 5 min',
        'baseline_median_5m' => 'Baseline median per 5 min', 'limit_per_hour' => 'Configured limit per hour', 'exception' => 'Exception', 'route_group' => 'Route group', 'route_name' => 'Route',
        'occurrences' => 'Occurrences', 'last_error_id' => 'Last error id', 'limit_count' => 'Configured limit (count)', 'ping_ms' => 'Probe time (ms)', 'long_running_queries' => 'Long-running queries',
        'connections_percent' => 'Connections used (%)', 'connections_active' => 'Connections active', 'connections_max' => 'Connections maximum', 'waiting_locks' => 'Waiting locks',
        'available' => 'Available', 'store' => 'Store', 'writable' => 'Writable', 'free_percent' => 'Free space (%)', 'low_percent' => 'Low-space mark (%)',
        'last_heartbeat' => 'Last heartbeat', 'age_minutes' => 'Age (min)', 'backup_state' => 'Backup state', 'backup_age_hours' => 'Backup age (hours)',
        'provider' => 'Provider', 'calls' => 'Calls', 'denied_401_403' => 'Denied (401/403)', 'throttled_429' => 'Throttled (429)', 'mfa_failures' => 'MFA failures',
    ],
];
