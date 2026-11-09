# Recommended deployment topology

Production Gate 2, 2026-10-19. This doc is based on the measurements in
[capacity-model.md](capacity-model.md) and [load-test-results.md](load-test-results.md).
Components marked *optional* are not required by any measurement taken so far.

```
                Internet / government network
                            │  HTTPS (TLS 1.2+)
                 ┌──────────▼───────────┐
                 │ WAF / reverse proxy  │  terminates TLS, sets X-Forwarded-*
                 │ + load balancer      │  health: GET /health/ready (200/503)
                 └───┬──────────────┬───┘  liveness: GET /health/live
                     │              │      no sticky sessions
            ┌────────▼───┐   ┌──────▼─────┐
            │ web node 1 │…  │ web node N │  nginx + PHP-FPM + OPcache, identical release,
            └──┬─────────┘   └──┬─────────┘  no local state (N ≥ 2 for availability)
               │  private network only
   ┌───────────┼────────────────┬──────────────────────┬────────────────────────────┐
   │           │                │                      │                            │
┌──▼───────┐ ┌─▼─────────────┐ ┌▼──────────────────┐ ┌─▼──────────────────────┐ ┌───▼───────────────┐
│PgBouncer │ │ object storage│ │ queue workers      │ │ scheduler (1 active)   │ │ Redis (optional)  │
│(txn pool)│ │ private+public│ │ default, notif.,   │ │ schedule:run each min  │ │ sessions/cache/    │
└──┬───────┘ │ buckets (S3)  │ │ long, integrations │ │ every task onOneServer │ │ limits if adopted  │
   │         └───────────────┘ └────────────────────┘ └────────────────────────┘ └────────────────────┘
┌──▼─────────────────────┐    streaming replication    ┌───────────────────────┐
│ PostgreSQL primary     │ ──────────────────────────► │ PostgreSQL standby    │  (HA; failover is
│ (canonical data,       │                             │ (hot standby)         │   NEEDS_DEPLOYMENT_
│  sessions, cache, jobs)│ ── WAL archive ──► pgBackRest repos ×2 (off-host)   │   CONFIGURATION)
└────────────────────────┘
```

## Which components the measurements require

| Component | Required? | Evidence |
| --- | --- | --- |
| ≥ 2 web nodes behind an LB | **Yes, for availability.** Capacity alone could be met by one large node at the profiles tested. | Throughput scales linearly with PHP workers ([capacity-model.md](capacity-model.md)). One node is a single point of failure. |
| PgBouncer (transaction pooling) | **Yes, once total PHP workers + queue workers approach `max_connections`.** | Connection budget in [database-capacity.md](database-capacity.md#connection-budget). Laravel opens one connection per FPM worker per request. |
| PostgreSQL standby + automated failover | **Yes for HA.** Not measured: no replica exists here. | [backup-pitr-restore.md](backup-pitr-restore.md). Marked **NEEDS_DEPLOYMENT_CONFIGURATION_POSTGRES_HA**. |
| Read replica for reports | **No (not yet).** | At the measured mix the primary served lookups in under 1 ms. The heavy aggregates are cached with stale-while-revalidate. Revisit if dashboard or Situation Room refreshes contend with writes. |
| Object storage for files | **Yes for multi-node.** | Photos, evidence and exports are on the node-local `local` disk by default ([load-balancer-readiness.md](load-balancer-readiness.md)). |
| Redis | **Optional.** | All shared state is in PostgreSQL today ([redis-readiness.md](redis-readiness.md)). It becomes worthwhile if per-request session and rate-limit writes start to matter. |
| Laravel Octane | **No.** | Not justified by measurements: per-request DB time, not framework boot, dominated every slow path. |
| Horizon | **No** (database queue). | It requires Redis queues. |
| CDN | Static assets only. | The application and API are confidential; never put them behind a public CDN cache. |
| Microservices / partitioning `employees` | **No.** | 3M employees with the existing indexes: canonical lookups under 1 ms. |

## Single points of failure (as configured today)

1. **PostgreSQL primary:** it holds canonical data *and* sessions, cache, locks and queues.
   Without a standby, every outage takes the whole platform down. This is the dominant SPOF.
2. **Scheduler host:** when it stops, the outbox stops publishing to the change feed. Webhooks,
   reminders and SLA escalation stop with it. A second host with the same cron is safe: every
   task is `onOneServer()`.
3. **Node-local `storage/app`:** until object storage is configured, uploads and exports exist
   on one node only.
4. **The load balancer itself:** use an HA pair or a managed LB.

## Network boundary

- **PostgreSQL, PgBouncer, Redis and the workers sit on a private network.** No public address,
  and they are reachable from the app and worker hosts only.
- **TLS:**
  - Public HTTPS at the proxy.
  - `DB_SSLMODE=require` (or `verify-full`) when the database is on another host. The default
    `prefer` silently falls back to plaintext.
  - Redis TLS if the infrastructure policy requires it.
- **Health endpoints:** answer only to the LB network (proxy rule). They expose ok/fail only,
  never hosts or versions.
- **Secrets:** no production secret is committed. `.env.production` is untracked; keep it in the
  host's secret store.
