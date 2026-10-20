-- Scale baseline snapshot (docs/production-readiness/scale-baseline.md). Read-only.
--   psql -d <db> -f loadtest/db/baseline.sql

\pset footer off
SELECT version();
SELECT pg_size_pretty(pg_database_size(current_database())) AS database_size;

\echo '== Largest tables (heap, indexes, total, live rows)'
SELECT c.relname AS table,
       pg_size_pretty(pg_relation_size(c.oid)) AS heap,
       pg_size_pretty(pg_indexes_size(c.oid)) AS indexes,
       pg_size_pretty(pg_total_relation_size(c.oid)) AS total,
       s.n_live_tup AS live_rows,
       CASE WHEN s.n_live_tup > 0 THEN round(pg_total_relation_size(c.oid)::numeric / s.n_live_tup) END AS bytes_per_row
FROM pg_class c JOIN pg_stat_user_tables s ON s.relid = c.oid
WHERE c.relkind = 'r'
ORDER BY pg_total_relation_size(c.oid) DESC LIMIT 25;

\echo '== Largest indexes'
SELECT indexrelname AS index, relname AS table, pg_size_pretty(pg_relation_size(indexrelid)) AS size, idx_scan
FROM pg_stat_user_indexes ORDER BY pg_relation_size(indexrelid) DESC LIMIT 25;

\echo '== Master-data counts'
SELECT 'employees' AS entity, count(*) FROM employees
UNION ALL SELECT 'employees active', count(*) FROM employees WHERE status = 'active'
UNION ALL SELECT 'organizations', count(*) FROM organizations
UNION ALL SELECT 'organization_units', count(*) FROM organization_units
UNION ALL SELECT 'positions', count(*) FROM positions
UNION ALL SELECT 'employee_assignments', count(*) FROM employee_assignments
UNION ALL SELECT 'employee_assignments current', count(*) FROM employee_assignments WHERE is_current
UNION ALL SELECT 'id_cards', count(*) FROM id_cards
UNION ALL SELECT 'entitlements', count(*) FROM entitlements
UNION ALL SELECT 'external_applications (API clients)', count(*) FROM external_applications
UNION ALL SELECT 'integration_events (outbox)', count(*) FROM integration_events
UNION ALL SELECT 'webhook_deliveries', count(*) FROM webhook_deliveries
UNION ALL SELECT 'cafeteria_transactions', count(*) FROM cafeteria_transactions
UNION ALL SELECT 'service_transactions', count(*) FROM service_transactions
UNION ALL SELECT 'daily_activity_logs', count(*) FROM daily_activity_logs
UNION ALL SELECT 'audit_logs', count(*) FROM audit_logs
UNION ALL SELECT 'users', count(*) FROM users
UNION ALL SELECT 'sessions', count(*) FROM sessions;

\echo '== Server settings'
SELECT name, setting, unit FROM pg_settings WHERE name IN (
  'max_connections', 'shared_buffers', 'work_mem', 'maintenance_work_mem', 'effective_cache_size', 'random_page_cost',
  'autovacuum', 'autovacuum_max_workers', 'autovacuum_vacuum_scale_factor', 'autovacuum_analyze_scale_factor',
  'statement_timeout', 'lock_timeout', 'idle_in_transaction_session_timeout', 'deadlock_timeout', 'log_lock_waits',
  'log_min_duration_statement', 'shared_preload_libraries', 'wal_level', 'archive_mode', 'max_wal_senders',
  'synchronous_commit', 'max_parallel_workers_per_gather', 'jit') ORDER BY name;
SELECT 'replicas' AS what, count(*) FROM pg_stat_replication UNION ALL SELECT 'in_recovery', pg_is_in_recovery()::int;
SELECT archived_count, failed_count, last_archived_time FROM pg_stat_archiver;

\echo '== Autovacuum state of high-write tables'
SELECT relname, n_live_tup, n_dead_tup, last_autovacuum, last_autoanalyze, autovacuum_count
FROM pg_stat_user_tables
WHERE relname IN ('audit_logs', 'cafeteria_transactions', 'service_transactions', 'card_verifications', 'integration_events',
                  'webhook_deliveries', 'api_request_logs', 'sessions', 'cache', 'jobs', 'employees', 'daily_activity_logs')
ORDER BY relname;
