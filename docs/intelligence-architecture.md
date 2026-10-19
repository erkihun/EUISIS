# Addis One Intelligence — architecture

Addis One Intelligence (Amharic: *የAddis One ብልህ ትንተና*) is the analytical and
diagnostic layer of Addis One. It helps executives, HR, system administrators
and developers understand what is happening, detect abnormal conditions, see
emerging risks with their evidence, and receive recommendations — while every
decision stays with a person.

It is one module with **two strictly separated intelligence domains**:

| Domain | For | Reads | Grant family |
| --- | --- | --- | --- |
| Business / management intelligence | executives, HR, planners | authoritative domain data through the existing services and aggregates | `intelligence.<business area>.view` |
| System health / diagnostic intelligence | system administrators, developers | telemetry the platform actually collects | `intelligence.<technical area>.view` |

They share infrastructure (scope, caching, the AI gateway, the alert engine)
but **never share permissions or data by default**: a technical grant opens no
HR figure, and an executive grant opens no stack trace.

## Canonical model

```
SIGNAL → EVIDENCE → AI EXPLANATION → RECOMMENDATION → HUMAN REVIEW → AUTHORIZED ACTION → VERIFICATION
```

Deterministic code produces the signal and the evidence. AI may detect,
analyse, summarise, explain, forecast and recommend. AI never disciplines,
scores, approves, decides, changes records or structure, or runs a destructive
command. Every AI output is advisory, carries its confidence, provenance
(provider, model, prompt version, run id) and is rendered as untrusted text.

## What the repository already had (inspected 2026-10-19)

- Laravel 12.69 / PHP 8.2+ (8.3 in CI), Inertia 2 + React 18 + TypeScript,
  Tailwind 4, Recharts 3, Pest 3. PostgreSQL in development, MySQL/MariaDB on
  the live server, SQLite in tests. Database queue (`default`, `notifications`,
  `long`), database cache and sessions by default; Redis optional; Reverb.
- **Executive Situation Room** (`app/Services/SituationRoom/*`): sixteen
  analytics categories on `SituationScopeResolver` / `SituationScope`
  (closure-table scope, fingerprint), `SituationPeriod`, `SituationFilters`,
  `SituationCache` (scope + filter + grants fingerprint, TTL from settings),
  `SituationPanel` (kpis / chart / table / notice) and the React panel kit
  (`Components/situationRoom/Panels.tsx`, `SituationChart`). Metrics catalog in
  `docs/situation-room-metrics-catalog.md`.
- Permission catalog (`database/seeders/data/permissions.php` +
  `PermissionCatalog`, `DefaultRoleMatrix`, `RbacSynchronizer`), Gate::before
  for Super Admin, `OrganizationScopeService`.
- `SystemSettingsRegistry` groups with EN/AM labels; `WriteAuditLogAction`
  with recursive redaction; `AuditEventType` (string enum, labels in
  `resources/js/i18n/*/auditLogs.ts`).
- Error handling: `ErrorLoggingService` (log only, error id, input
  redaction), `RequestCorrelationId` middleware, health endpoints
  `/health/live`, `/health/ready`, `/up`; backup status services; notification
  channels database + mail (SMS through `SmsGateway` with a budget).
- **Absent:** any AI/LLM/embedding/vector package or code; request or
  latency metrics; slow-query logging; error/incident tables; deployment or
  release metadata; frontend error reporting; Horizon; Telegram sender.

## Module structure

```
Intelligence
├── Overview                       intelligence.view
├── Executive Intelligence         intelligence.executive.view
├── Workforce Intelligence         intelligence.workforce.view
├── Service Intelligence           intelligence.service.view
├── Public Voice Intelligence      intelligence.public-voice.view
├── Performance Intelligence       intelligence.performance.view
├── Forecasting                    intelligence.forecasting.view
├── Data Quality Intelligence      intelligence.data-quality.view
├── Knowledge Assistant            intelligence.knowledge.view
│
├── System Health                  intelligence.system-health.view
├── Active Incidents               intelligence.incidents.view (+ .manage)
├── AI Diagnostics                 intelligence.diagnostics.view
├── Error & Failure Analysis       intelligence.diagnostics.view
├── Application Performance        intelligence.application-health.view
├── Database Health                intelligence.database-health.view
├── Redis & Queue Health           intelligence.queue-health.view
├── Integration Health             intelligence.integration-health.view
├── Deployment Analysis            intelligence.deployment-analysis.view
├── Predictive Failure Monitor     intelligence.diagnostics.view
├── Recommendations                intelligence.recommendations.view (+ .review)
├── Runbooks                       intelligence.runbooks.view (+ .execute)
│
└── AI Governance & Audit          intelligence.governance.view (+ .manage)
```

`intelligence.view` opens the launcher and the Overview; each area needs its
own grant on top. The registry is `App\Services\Intelligence\IntelligenceArea`
(one enum case per area: grant, section, icon, href). The sidebar shows the
major destinations only (Intelligence, System Health, Incidents, Knowledge
Assistant, AI Governance); the landing page is the category launcher and shows
only permitted cards.

### Permission = WHAT, scope = WHERE

Every business figure is computed inside the viewer's organization scope
(`SituationScopeResolver` on the published hierarchy, descendants included,
siblings never). Scope is resolved on the server; the client never carries
scope authority. Cache keys include the scope fingerprint, the period, the
filters and the grants fingerprint, so one organization's analytics can never
be served to another.

### Role-appropriate detail

- Executive: impact, trend, affected service, severity, business consequence,
  recommended management action. No stack traces.
- System admin: component health, queues, infrastructure, integrations,
  remediation guidance.
- Developer / technical admin (`intelligence.diagnostics.view`): exception
  signature, endpoint, service area, query behaviour, deployment correlation,
  "suggested area to inspect". Never secrets, never PII.

## Decisions taken (defaults, documented so they can be changed)

| # | Decision | Why |
| --- | --- | --- |
| D-1 | Business areas reuse the Situation Room building blocks (scope resolver, period, filters, cache, panel model, panel React kit) instead of a second analytics stack. | One scope rule, one cache rule, one chart kit; the Situation Room stays the executive command centre and links into Intelligence for depth. |
| D-2 | Thresholds live in the `intelligence` settings group, **null by default = rule off**, or are baseline-derived (robust deviation over a configurable window). No constant is hard-coded. | §112–113 of the brief: no invented SLOs. |
| D-3 | City Admin and Public Service Bureau Admin receive the business grants but are **withheld the technical grants** (`IntelligenceRoles::CITY_ADMIN_WITHHELD`). Super Admin and System Admin receive everything. Scoped roles receive nothing by default. | §5–7: technical access is not implied by executive access. Role grants beyond this are `NEEDS_POLICY_DECISION`. |
| D-4 | Telemetry is collected into `intelligence_signals` by a 5-minute scheduled collector (queues, failed jobs, DB/cache latency, storage, backup state, scheduler heartbeat) plus per-minute request counters aggregated in cache by a light middleware and flushed by the collector; unhandled exceptions are fingerprinted at the `ErrorLoggingService` boundary. | §25, §30, §34–35, §78: nothing fabricated, no per-request database writes. |
| D-5 | Health states are `healthy / degraded / unhealthy / unknown`; `unknown` whenever a signal is not collected. | §26: unknown beats fake green. |
| D-6 | Incident statuses `open / investigating / mitigated / resolved / closed`; severities `critical / high / medium / low / info`, assigned by rule + measured impact, never by the model. | §27–29; no equivalent workflow existed. |
| D-7 | AI providers sit behind `LLMGateway` with `none` (default), `openai_compatible` and `anthropic` drivers, configured only from the environment (`INTELLIGENCE_AI_*`). The UI shows Configured / Not configured. Timeouts, bounded retries and a cache-backed circuit breaker. | §23, §80–82, §115–116. |
| D-8 | Knowledge retrieval starts with PostgreSQL full-text search over approved, classified documents (LIKE fallback on SQLite); embeddings are behind `EmbeddingProvider` / `VectorStore` interfaces and off until a vector store exists. Permission and classification filters run **before** retrieval. | §20–22: no vector infrastructure exists; `NEEDS_INFRASTRUCTURE_VECTOR_STORE`. |
| D-9 | Runbooks are read-only guidance in phase 1; execution stays disabled behind a setting and `intelligence.runbooks.execute`. | §49–51. |
| D-10 | Forecasts are snapshots with method, window, confidence and limitations; `FORECAST_DATA_INSUFFICIENT` under six monthly points. Only linear/seasonal-naïve baselines, no custom ML. | §13, §69–72. |
| D-11 | Deployment metadata is recorded by `intelligence:record-deployment` (release, commit, deployed_at, environment) when the deploy script calls it; absent → `NEEDS_DEPLOYMENT_CONFIGURATION`, correlation is skipped, never guessed. | §44, §107. |
| D-12 | Scheduled business computations (alert engine, forecast snapshots) act as the **first active Super Admin** with an unrestricted scope (`SystemContext`); without one they are skipped and say so. | The platform has no system account; a Super Admin holds every catalog grant through the RBAC synchroniser, and the analyses check grants directly. A dedicated service account is `NEEDS_POLICY_DECISION_SYSTEM_ACCOUNT`. |
| D-13 | Alerts dedupe on a `source_key`, resolve after three quiet runs, re-fire only after a 60-minute cooldown; business alerts are city-wide and notify unrestricted executives at most once a day; technical alerts are announced as incidents. | §65–67: no flooding, one alert per condition, acknowledgement by people only. |
| D-14 | The root-cause assistant runs as a queued job on the `long` queue from an explicit button; its answer is stored as an assessment with provenance and its next checks become AI recommendations awaiting review. | §101–103, §116–118: human-triggered, advisory, reviewable. |

## NEEDS_* registry

| Code | Where | What is needed |
| --- | --- | --- |
| NEEDS_POLICY_DECISION_ALERT_ESCALATION | alerts | who is escalated to, after how long, on which channel |
| NEEDS_POLICY_DECISION_ROLE_GRANTS | permissions | which scoped roles receive which intelligence grants |
| NEEDS_POLICY_DECISION_RUNBOOK_EXECUTION | runbooks | whether any safe action may run from the web app |
| NEEDS_POLICY_DECISION_SYSTEM_ACCOUNT | scheduled analyses | a dedicated service account instead of the first Super Admin (D-12) |
| SLO_CONFIGURATION_REQUIRED | system health | latency / error-rate / availability objectives |
| NEEDS_INFRASTRUCTURE_VECTOR_STORE | knowledge | pgvector or an external vector store for embeddings |
| NEEDS_INFRASTRUCTURE_READ_REPLICA | analytics | only if measured load requires it |
| NEEDS_DEPLOYMENT_CONFIGURATION | deployment analysis | call `intelligence:record-deployment` from the release script |
| NEEDS_DATA_SERVICE_LINKAGE | service intelligence | submissions without `position_service_id` / `service_id` are reported as unlinked, never inferred |
| NEEDS_LANGUAGE_REVIEW | labels | formal review of the Amharic module terminology |

## Phases

| Phase | Delivers |
| --- | --- |
| 0 | Permissions, settings group, area registry, routes, launcher, sidebar, i18n, docs, tests |
| 1 | Telemetry collector and request counters, error fingerprints, signals/incidents/recommendations tables, detection engine with baselines, System Health, Incidents, Diagnostics, Application/Database/Queue/Integration health, Runbooks (read-only), Recommendations |
| 2 | Business areas (Executive, Workforce, Service, Public Voice, Performance, Data Quality) on the Situation Room blocks, insight cards with evidence, cross-domain correlation, Situation Room summary card, metrics catalog |
| 3 | AI gateway, prompt registry, AI runs and audit, root-cause assistant, Knowledge Assistant (documents, chunks, FTS retrieval, cited answers), Governance |
| 4 | Forecasting snapshots and evaluation, alert engine with dedup/cooldown/acknowledgement, notifications, retention, Situation Room link card, final verdict |

All five phases are delivered (2026-10-19). Insight narratives (the
`insight_narrative` prompt) are registered and testable through the gateway
but not yet wired to a page; the business areas' deterministic insight text
stands on its own.

Related documents: `docs/intelligence-metrics-catalog.md`,
`docs/intelligence-security.md`, `docs/intelligence-system-health.md`,
`docs/intelligence-business.md`, `docs/intelligence-knowledge.md`,
`docs/intelligence-ai-governance.md`, `docs/intelligence-alerts.md`,
`docs/intelligence-runbooks.md`.
