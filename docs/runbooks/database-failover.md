# Database failover runbook and drill

Gate G1 ([../go-live/go-live-gates.md](../go-live/go-live-gates.md)) needs a
standby database and a rehearsed failover. This runbook covers both engines
the platform meets: PostgreSQL (the production engine, decision D-5, with
pgBackRest PITR: [postgresql-pitr.md](postgresql-pitr.md)) and MySQL/MariaDB
(the current Plesk live server). Hosting tools differ; the steps and the
evidence do not. Nothing here has been executed yet.

## Prerequisites

| Item | PostgreSQL | MySQL / MariaDB |
| --- | --- | --- |
| Standby | streaming replica (asynchronous; synchronous only if the network allows) | replica with GTID-based replication |
| Point-in-time recovery | pgBackRest full/incremental + WAL archive | full backup (mariabackup) + binary logs kept off-server |
| Off-server copy | second pgBackRest repository | backups and binlogs copied to separate storage |
| Lag monitoring | `pg_stat_replication`, alert on lag > RPO | `SHOW REPLICA STATUS` (`Seconds_Behind_Master`), alert on lag > RPO |
| Application connection | one name (DNS or proxy) that can be repointed | same |

## Planned failover (drill) — target RTO 1 hour

| # | Step | Check |
| --- | --- | --- |
| 1 | Announce the drill window; name the DR Coordinator and the Incident Manager | — |
| 2 | Record lag on the standby | below the 15-minute RPO |
| 3 | `php artisan down`; set `CORE_MASTER_DATA_WRITES=frozen`, `config:cache` | no writes |
| 4 | `php artisan core:snapshot --output=failover-before.json` | file written |
| 5 | Stop writes on the primary; wait until the standby has replayed everything | zero lag |
| 6 | Promote the standby (`pg_ctl promote` / `pg_promote()`; MariaDB: `STOP REPLICA; RESET REPLICA ALL;` and make it writable) | standby accepts writes |
| 7 | Repoint the application connection name; `config:cache`; restart workers | `/health/ready` 200 |
| 8 | `php artisan core:snapshot --compare=failover-before.json` | exit 0 |
| 9 | `php artisan backbone:audit-integrity --strict`, `audit:verify`, `core:smoke` | all pass |
| 10 | Open writes (`CORE_MASTER_DATA_WRITES=open`), `php artisan up` | sign-in and one card verification work |
| 11 | Rebuild the old primary as the new standby | replication healthy |
| 12 | Record timings: start, writes stopped, standby promoted, service back | total within 1 h |

## Unplanned failover

Same steps from 5, starting with confirming the primary is really lost (to
avoid two primaries). Data written after the last replicated transaction is
lost up to the replication lag; the RPO is met only if lag was under 15 minutes.

## Evidence for the gate

The DR Coordinator writes a drill record (date, engine, timings, lag at
failover, snapshot comparison result, problems found) and gives it a
reference. The Database/Infrastructure Administrator sets
`GOLIVE_FAILOVER_DRILL=<reference>` in production. A full database restore
drill (target 4 h) is recorded separately as `BACKUP_OBJECTIVES_VALIDATED_BY`
([restore-validation.md](restore-validation.md)).

## Never

No `migrate:fresh`, `db:wipe`, dropped databases or destructive rollbacks;
no secrets in the drill record; never leave writes frozen after the drill.
