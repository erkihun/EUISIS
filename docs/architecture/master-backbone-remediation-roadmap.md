# Master backbone remediation roadmap

Companion to the [master backbone audit](addis-one-master-backbone-audit.md). Ordered by dependency, not by fashion: correct authorization and identity first, then contracts, then propagation, then scale and rollout. Effort is relative (S/M/L/XL), not a time estimate. Finding IDs match the audit.

**Ground rules for every item:** no destructive migration; no re-keying of UUIDs; no change to `employee_number`; no mass regeneration of `public_card_uuid`; assignment and card history preserved; existing v1 fields kept until consumers migrate; business rules changed only by an explicit owner decision.

## Dependency chain

```
Contain + fix authorization (F1, F16)
  → fix verification semantics (F5, F17) and machine-principal eligibility (F4)
    → fix consumer contract (F3) with shared fixtures
      → organization-bounded grants + field policy (F2)
        → integrity of position/assignment history (F10, F20)
          → schemas, error contract, idempotency (F6, F14, F7)
            → change cursor + tombstones (F8)
              → outbox + signed webhooks (F9)
                → gateway, shared topology, 3M + recovery proof (F11)
                  → partner onboarding at scale
```

## Immediate (before any further partner exposure)

| # | Item | Findings | Effort | Prerequisite | Acceptance evidence |
| --- | --- | --- | --- | --- | --- |
| I-1 | Inventory every live token holding `*` or `provider:access`; revoke or reissue with explicit scopes | F1 | S | — | Token inventory report; no wildcard tokens on master routes |
| I-2 | Stop `*`/`provider:access` from satisfying master-data scopes; require a supported principal type on `/api/v1` directory routes; keep provider compatibility only on provider routes | F1 | S | I-1 | Inverted `BackboneBoundaryEvidenceTest` case returns 403 |
| I-3 | Partner verification must reject `qr_status != active` and non-current cards, through one shared credential-state check used by public checker, partner API and eligibility | F5 | S | — | Tests for current / replaced / revoked QR / revoked card / inactive employee |
| I-4 | Eligibility service accepts a machine principal (actor type for audit), not only `?User` | F4 | S | — | Registered application gets a 200 eligibility result |
| I-5 | Return `employee.id` in verification; cafeteria must deny when it is missing; replace the invented mock with a fixture captured from the provider | F3 | S | I-3, I-4 | Shared contract fixture used by both apps' tests |
| I-6 | Stop returning `url('storage/...')` photos; return no photo until a signed short-lived link exists | F17 | S | — | Response contains no public storage URL |
| I-7 | Triage npm advisories (`shell-quote` via `concurrently`, `source-map-js`) for reachability; update dev tooling | F12 | S | — | `npm audit` clean or documented exceptions |

## Phase A — authorization and integrity foundations

| # | Item | Findings | Effort | Prerequisite | Migration risk / compatibility |
| --- | --- | --- | --- | --- | --- |
| A-1 | Move the six provider-service routes onto `ExternalApplicationGate` with a provider binding on the application; read `provider_code` per item in offline sync | F16 | M | I-2 | Existing User-token provider clients need a migration window |
| A-2 | Organization/subtree binding and approved field set per application (policy first) | F2 | M | Owner decision on city-wide vs bounded access | Existing city-wide consumers must be explicitly approved or migrated; do not narrow silently |
| A-3 | Retire legacy unrestricted-endpoint grace by owner-approved list | F2 | S | A-2 | Notify consumers |
| A-4 | Guard `ArchivePositionAction` against active assignments (block or require closure) | F10 | S | Owner rule | None for history |
| A-5 | Transfer and vacancy transfer pin the destination's in-force JD and hierarchy version; vacancy transfer sets `organization_unit_id` | F10 | M | Owner rule on which JD is "in force" | Existing rows untouched; backfill only with reconciliation report |
| A-6 | One capacity definition: `capacity`, `occupied_count`, `available_slots`; align `availableSlotsForPosition()` with placement; keep `vacant` as a deprecated alias | F10 | M | Owner definition | Additive API fields |
| A-7 | Hierarchy resolver filters `effective_from <= today` for "current" | F20 | S | — | Behavior change only for future-dated versions |
| A-8 | Settlement report filters by period; transaction picks the active, in-date entitlement | F18, F19 | S | — | Totals change to correct values; inform providers |
| A-9 | Publish immutability/non-reuse promise for UUID `id`s in the API contract | — | S | — | Documentation only |
| A-10 | Field-ownership rule between `users` and `employees` (name, phone, national ID); decide whether provider users may link to employees | — | M | Policy | No data change until decided |

## Phase B — contracts, idempotency, propagation

| # | Item | Findings | Effort | Prerequisite | Notes |
| --- | --- | --- | --- | --- | --- |
| B-1 | OpenAPI 3.1 document for existing v1, generated or hand-maintained, validated in tests against real responses | F14 | M | Phase A response changes | Start from `docs/api-organization-employee-v1.md` and `ApiEndpointDefinition` |
| B-2 | One error envelope (`message`, `error_code`, `request_id`, optional `errors`) for all v1 routes; freeze published status vocabularies; strict ISO `updated_after` | F14 | M | B-1 | Additive fields first |
| B-3 | Durable idempotency: atomic claim, request fingerprint, stored result, per-application namespace; unique business key in satellite cafeteria transactions | F6 | M | — | Add unique index after duplicate check |
| B-4 | Offline sync either records transactions through the recording action or is renamed verify-only; cap batch size | F7 | S | B-3 | Contract decision |
| B-5 | Correlation ID stored in audit logs and `api_request_logs`; add latency and token ID; allow/expose `X-Request-ID` and `Idempotency-Key` in CORS | F13 | S | — | |
| B-6 | Minimize audit copies of employee PII (`RegisterEmployeeAction` logs the full array; redaction misses `phone`, `email`, `address`, `date_of_birth`) | F13 | S | Approved audit purpose | |
| B-7 | Change journal / cursor feed with `(change_seq, id)` ordering and tombstones for org, unit, position, employee, assignment, card | F8 | L | A-5, B-2 | Database table; no partner DB access |
| B-8 | Transactional outbox written in the same commit as the domain change; queue worker delivers | F9 | L | B-7 | Laravel queue + DB outbox is sufficient; Kafka/RabbitMQ not justified by current evidence |
| B-9 | Signed webhook delivery: subscriptions per application, HMAC with key ID and timestamp, replay window, retry with backoff/jitter, delivery history, dead-letter and operator replay, secret rotation with overlap | F9 | L | B-8, A-1 | `callback_url` exists but nothing sends to it today |
| B-10 | As-of reads for assignment and structure | F8 | M | A-5, A-7 | Only after history correctness is proven |
| B-11 | Consumer/provider contract tests (cafeteria first) | F3, F14 | M | B-1 | |

## Phase C — operations, scale, rollout

| # | Item | Findings | Effort | Prerequisite |
| --- | --- | --- | --- | --- |
| C-1 | Shared cache/lock store (Redis or equivalent) for rate limits, idempotency and locks; shared private object storage for photos and documents; verify trusted proxies | F11 | M | Deployment design |
| C-2 | Edge gateway: TLS, coarse rate limits, request size limits, request-ID injection, allowlists | F11 | M | Phase A, C-1 |
| C-3 | Isolated 3M-employee test: deep sync, simultaneous partners, scan-write load, cold/warm cache, revoked credentials, queue catch-up after outage, one-node loss | F11 | XL | B-7, C-1, agreed SLOs |
| C-4 | Connection budget; PgBouncer only if worker × node count exceeds safe DB connections; test transaction pooling compatibility | F11 | M | C-3 measurements |
| C-5 | PITR and restore drill including object storage and encryption keys; replay/reconcile partners after restore | F11 | L | B-7, agreed RPO/RTO |
| C-6 | Integration dashboard additions: per-client latency, error rate, webhook backlog and failures | — | M | B-5, B-9 |
| C-7 | Pilot one partner end to end (onboarding, rotation, revocation, outage, reconciliation) before city-wide rollout | — | M | All of the above for that partner's scopes |

## Future (only when justified)

- OAuth 2.0 client credentials / federation when partners need short-lived tokens issued by a shared government identity provider (F15).
- Read replicas for reporting and sync once measurements show primary pressure; keep revocation and post-command reads on the primary.
- Extraction of webhook delivery or heavy reporting into separate services only if measured scaling needs demand it. Keep the modular monolith by default.
- Governed onboarding command for an external recruitment system.

## What not to do

- Do not add duplicate `*_uuid` columns to replace existing UUID primary keys.
- Do not regenerate card QR references or re-print cards for API reasons.
- Do not re-key assignments or rewrite closed intervals.
- Do not give any partner database access or full dumps.
- Do not introduce Kafka, RabbitMQ or microservices to satisfy a diagram.
- Do not broaden `employees.basic_read` grants to work around the missing verification UUID.
