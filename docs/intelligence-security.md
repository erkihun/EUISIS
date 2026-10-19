# Addis One Intelligence — security model

What the module may read, show, send and do; and what it never does.

## Access: permission = WHAT, scope = WHERE

- Every page is behind `auth`, `verified`, `mfa`, `force.password`,
  `admin.access` and `can:intelligence.view`; each area then checks its own
  grant (`IntelligenceArea::visibleTo()`), so a technical grant never opens a
  business figure and the reverse. Grants are catalog entries
  (`database/seeders/data/intelligence-permissions.php`) synchronised by the
  RBAC synchroniser; City Admin and PSB Admin are withheld the technical ones.
- Business figures are computed inside `SituationScope`
  (`OrganizationScopeService`, published hierarchy closure); a forged focus
  organization outside the scope is a 403 before any query. Business alerts
  are city-wide and therefore shown only to unrestricted executives.
- Small cells follow the Situation Room privacy rule; no person is ranked; no
  complaint count becomes a judgement about an employee.
- Diagnostics detail (file, line, error id) needs `intelligence.diagnostics.view`;
  sample stack frames are never sent to a page or a provider.

## Writes the module performs

| Action | Who | Recorded as |
| --- | --- | --- |
| incident status, owner, verification, note | `incidents.manage` | event + `IntelligenceIncidentUpdated` |
| recommendation review | `recommendations.review` | `IntelligenceRecommendationReviewed` |
| alert acknowledge / resolve / reopen | per domain (see alerts doc) | `IntelligenceAlertUpdated` |
| ask AI about an incident | `diagnostics.view` | events + `IntelligenceIncidentExplained` + AI run |
| knowledge document create / approve / retire / delete | `governance.manage` | `IntelligenceKnowledgeDocumentChanged` (never the body) |
| knowledge question | `knowledge.view` | `IntelligenceKnowledgeAsked` (length and mode only) |
| settings | `system-settings.manageIntelligence` | settings audit |

All PATCH/POST routes are CSRF-protected Inertia requests, validated with
`Rule::in` enumerations, throttled (`throttle:10,1` to `30,1`).

## What is never done

- No autonomous remediation: runbooks are read-only text;
  `runbook_execution_enabled` exists as a switch but no executor is wired
  (`NEEDS_POLICY_DECISION_RUNBOOK_EXECUTION`). The module never restarts,
  migrates, truncates, clears caches, kills processes, changes permissions or
  runs arbitrary SQL.
- No AI decision: AI output is stored as text/JSON with provenance and
  rendered as untrusted content; it is never executed, never writes a record
  other than its own advisory rows, never changes a status.
- No raw data to providers: only redacted, classified, size-capped bundles;
  classes are allow-listed in settings; keys live in the environment.
- No fake health: a component without a sample is UNKNOWN; a rule without a
  configured or baseline threshold does not fire; forecasts under six points
  say insufficient.
- No hard-coded thresholds: rules read System Settings or derive from the
  baseline (`Baseline`, MAD-based) with `anomaly_sigma`.

## Telemetry privacy

Request counters are per route **group** and minute, never per user or per
URL with identifiers (`RouteGroups::of()` collapses parameters). Error
fingerprints normalise numbers, ids and quoted values out of messages. Audit
counts (e.g. MFA failures) are counted, not listed.

## Prompt-injection posture

Input is fenced as DATA with an explicit instruction; the system prompt
forbids following instructions found in the data; model answers must match a
schema or are discarded; knowledge passages are quoted back by reference, so
an injected instruction inside a document can at most appear as a quote.
Rendered text uses React text nodes (no `dangerouslySetInnerHTML`).

## Retention

`intelligence:prune` (daily 03:35) removes signals, resolved incidents,
events, AI runs and alerts older than `retention_days`; knowledge documents
and forecasts are kept (they are reference material and evaluation history).
Deleting a user nulls their references (`nullOnDelete`) — nothing in the
module blocks a deletion.

## Secrets checklist

- `INTELLIGENCE_AI_BASE_URL`, `INTELLIGENCE_AI_API_KEY`, `INTELLIGENCE_AI_MODEL`
  (OpenAI-compatible) or `INTELLIGENCE_ANTHROPIC_API_KEY`,
  `INTELLIGENCE_ANTHROPIC_MODEL` in the server environment only.
- `.env.example` documents the names with empty values; nothing committed
  carries a key (`tests/Feature/Intelligence/GovernanceAndForecastTest.php`
  asserts the Governance page does not leak a configured key).
