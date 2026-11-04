# Production monitoring of the application itself

The Service Monitoring module watches the city's public services. This page is
about watching **Agelgay itself** in production: whether it is up, whether it is
throwing errors, and where its logs go. Everything here is self-hosted; nothing
leaves the hosting environment (docs/hosting-security-requirements.md).

## 1. Is it up? Health probes

| Probe | Path | Middleware | Use |
|---|---|---|---|
| Liveness | `GET /health/live` | none (no session, no CSRF) | Load balancer and uptime checker. Always 200 while PHP answers. |
| Readiness | `GET /health/ready` | none | 200 only when the database, cache, queue and storage respond; 503 otherwise. Take the node out of rotation on 503. |
| Laravel default | `GET /up` | none | Kept for tools that expect it. |

Point an uptime checker (Uptime Kuma is the usual self-hosted choice) at
`/health/ready` on every application node every 60 seconds, and alert operators
on two consecutive failures. Readiness checks also run inside `backup:health
--monitor` and `production:readiness`.

## 2. Is it throwing errors? Error tracker

Exceptions are reported to a Sentry-compatible tracker through the
`error_tracker` log channel (`App\Logging\ErrorTrackerHandler`). GlitchTip is the
recommended self-hosted server; self-hosted Sentry and Bugsink speak the same
protocol.

```env
LOG_STACK=daily,error_tracker
ERROR_TRACKER_DSN=https://<public-key>@glitchtip.internal.gov.et/<project-id>
ERROR_TRACKER_LEVEL=error
```

What the handler does, and does not do:

- Sends the exception class, message, stack frames (file, line, function) and
  scalar log context. The cause chain is kept.
- Never sends request bodies, headers, cookies, sessions or files. Context keys
  that look like secrets (`password`, `token`, `otp`, `key`, `authorization`,
  `cookie`, `session`, `credential`) are dropped before sending.
- Fails silently: a tracker that is down, slow (3 s timeout) or misconfigured
  never breaks a request, and never triggers a second report of itself.
- Is inert without a DSN, so development and test environments need nothing.
- Tags each event with `APP_RELEASE` (see docs/governance/release-management.md),
  the environment and the host name, so an error can be traced to a version.

Operators alert on the tracker's "new issue" and "regression" events, not on
every occurrence. One issue per deploy that did not exist before is the signal
that matters during the pilot.

## 3. What happened? Log aggregation

Each node writes rotating files (`LOG_STACK=daily`, 14 days, config/logging.php).
For more than one node, add `syslog` to the stack and forward to one collector:

```env
LOG_STACK=daily,syslog,error_tracker
```

Any syslog collector works (rsyslog to a central host, or Loki + Grafana when
dashboards are wanted). Request ids are in every log line
(docs/architecture/adr/0004-go-live-gates-recovery-targets-retention-and-runtime.md),
so one request can be followed across nodes.

Retention of log tables inside the database is scheduled in routes/console.php
(config/retention.php, docs/governance/retention-matrix.md); file logs follow the
14-day rotation (`LOG_DAILY_DAYS`); the tracker keeps issues for 90 days by default.

## 4. The weekly operator check

- `/health/ready` green on every node, uptime checker shows no gaps.
- Error tracker: zero new issues since the last deploy, or each one has an owner.
- `php artisan backup:health` green, restore test not older than 35 days
  (docs/runbooks/restore-validation.md).
- Queue depth and failed jobs on the Intelligence › System health page.
- Disk on the log and backup volumes under 70 %.
