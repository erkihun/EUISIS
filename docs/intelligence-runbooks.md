# Addis One Intelligence — runbooks

Runbooks are **read-only guidance** (`RunbookCatalog`, text in
`lang/{en,am}/intelligence_system.php` under `runbooks`). An incident links
to the runbook of its rule; the Runbooks area lists them for
`intelligence.runbooks.view`. No step is executed by the application
(`runbook_execution_enabled` is a switch without an executor until
`NEEDS_POLICY_DECISION_RUNBOOK_EXECUTION` is taken).

| Key | Rules | Components | Steps |
| --- | --- | --- | --- |
| `queue_backlog` | queue_backlog, failed_jobs | queue | 6 |
| `database_pressure` | db_latency, db_connections | database | 6 |
| `redis_unavailable` | redis_unavailable, cache_unavailable | redis, cache | 5 |
| `integration_failure` | integration_failure | integrations | 5 |
| `storage_full` | storage | storage | 5 |
| `deployment_regression` | http_error_rate, http_latency, error_repeat | application | 6 |
| `pdf_generation_failure` | error_repeat | application | 5 |
| `backup_failure` | backup | backups | 4 |
| `scheduler_stopped` | scheduler_missed | scheduler | 4 |
| `auth_failure_spike` | auth_failures | security | 4 |

Each runbook has a title, a summary and numbered steps; every step is advice
for a person at a terminal with the right access. Risk classes
(`safe_check` / `potential_fix` / `high_risk`) are carried by the
recommendations an incident generates, not by runbook steps. Adding a
runbook: add the key to `RunbookCatalog::RUNBOOKS` with its rules, step count
and components, then its texts in both languages (the dictionary test checks
parity).
