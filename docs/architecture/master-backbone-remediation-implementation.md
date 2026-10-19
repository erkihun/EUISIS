# Master backbone remediation — implementation record

Date: 19 October 2026 (Africa/Addis_Ababa). Baseline: the readiness audit in
[addis-one-master-backbone-audit.md](addis-one-master-backbone-audit.md)
(score 60/100, verdict `MASTER_BACKBONE_ARCHITECTURE_NOT_READY`).
Implementation commit: `94d454fe` on `main`.

This record states what changed, how it was verified, and what is still open.
It does not certify a deployed environment and makes no 3M-employee claim.

## 1. Findings addressed

| ID | Priority | Status | What changed | Evidence |
| --- | --- | --- | --- | --- |
| F1 | P0 | FIXED | `EnsureApiScope` no longer treats `provider:access` as every scope. A registered application needs the exact scope on its token **and** in `allowed_scopes` (revocation is immediate). A personal token needs the exact scope or `*`; `provider:access` counts only on routes marked `api.scope:<scope>,provider` and only for the provider scopes (`ApiScope::PROVIDER_COMPATIBLE`). A person is also held to the screen permission behind the scope (`IntegrationAccessService::HUMAN_PERMISSION`). | `MasterBackboneRemediationApiTest` F1 cases; `IdCardIntegrationApiTest` "no longer lets a provider-portal token…" |
| F2 | P1 | FIXED | `external_applications.organization_access` (`city` / `scoped` / `unreviewed`) and `external_application_organization_scopes` (`self` / `subtree`, expanded through the latest published hierarchy closure). Every directory, structure, verification and eligibility controller applies the bound; outside it a record is **404**. Existing applications were migrated to `unreviewed` (deny until an administrator decides — never silently city-wide). People use `OrganizationScopeService`. | F2 cases incl. subtree/self/unreviewed; SEC-010 tests inverted |
| F3 | P0 | FIXED | Verification returns `employee_uuid`, `card_uuid`, `organization_uuid`, `assignment_status`, `verified_at`. The cafeteria consumer asks eligibility for `employee_uuid` and **denies** (`employee_reference_missing`) when it is absent instead of skipping the check. Its fixtures no longer invent `employee_id`. | F3 end-to-end case; cafeteria `ServeEmployeeTest` (fail-closed, correct UUID sent), `ScanCalendarTest` |
| F4 | P1 | FIXED | Eligibility passes `humanActor()` (null for a machine) and the caller label for audit; response adds `service`, `effective_context`, `checked_at`; missing `service_type` is 422. Provider controllers do the same, so no `TypeError`. | F3/F4 case; 422 case |
| F5 | P0 | FIXED | `valid` requires status `active`, `revoked_at` null, not expired, `qr_status = active` **and** `is_current`. `status` reports `revoked` / `expired` / `replaced` accordingly. | F5 dataset (7 states) |
| F6 | P1 | FIXED | `api_idempotency_keys` with a unique index on (principal type, principal id, route, key). The first request claims the key by insert; a concurrent duplicate loses the insert and gets 409 `idempotency_in_progress`; a completed key replays the stored response with `Idempotent-Replay: true`; the same key with a different body is 409 `idempotency_key_reused`. Failures release the key. Keys are namespaced per principal, not per token. | F6 cases incl. DB unique violation and principal namespacing |
| F16 | P1 | FIXED | Provider routes now run `api.external` (status, IP, endpoint, rate limit, log) then `provider.scope`. A machine principal must be bound (`external_applications.service_provider_id`) and every provider it names — route `{provider}`, `provider_code`, each offline item — must be that provider. People keep the provider organization-scope rule, now checked per offline item. Offline batch capped at 200; unknown codes are reported per item instead of a 404 for the batch. | F16 cases; batch cap case |
| F17 | P1 | FIXED | `photo_url` is a 5-minute `URL::temporarySignedRoute` to `GET /api/v1/employees/{employee}/photo`, bound to the issuing principal. Serving re-checks the principal (active, `employees.photo_read` or `employees.view`, organization bound), streams from the private disk (legacy public files still resolved), `Cache-Control: private, no-store`, `nosniff`. Callers without photo access get `photo_url: null`. | F17 cases: serve, tamper, revoke, expire, withhold |
| F18 | P2 | FIXED | Settlement period is `YYYY-MM` or `YYYY-MM-DD` and bounds `occurred_at` (half-open); response adds `period_start`, `period_end`, `transaction_count`; invalid period 422. | F18 case |
| F19 | P2 | FIXED | `Entitlement::inForceFor()` — active, inside effective dates, provider-specific before provider-neutral, latest start first. | F19 case |
| F10 (part) | P1 | FIXED (archive guard, transfer JD) | `ArchivePositionAction` refuses while a current active assignment occupies the position (validation message EN/AM, not a 500). `CompleteTransferAction` and `CompleteVacancyTransferAction` set the destination job description in force on the effective date; the vacancy transfer also sets the destination unit. History keeps the previous JD. | `MasterBackboneDomainRemediationTest` |
| F12 | P1 | FIXED (dependency) | `npm audit fix` (lock-file only): `shell-quote` (via `concurrently`) and `source-map-js` — both build tooling, not shipped. `npm audit`: 0. `composer audit`: 0. | command output in §4 |

Supporting changes: `ApiError` error body (`message`, `error_code`, `request_id`);
`X-Request-ID` recorded in `api_request_logs.metadata`; CORS allows
`Idempotency-Key` / `X-Request-ID` and exposes `X-Request-ID`,
`Idempotent-Replay`, `Retry-After`; the endpoint catalog strips the `,provider`
mode from the stored scope; API Management edits organization access, grants and
provider binding and shows access-review findings; `php artisan api:access-review`
lists `UNREVIEWED`, `UNSCOPED`, `OVERPRIVILEGED`, `LEGACY_CREDENTIAL`,
`PROVIDER_UNBOUND` without printing tokens.

## 2. Not addressed (still open)

| ID | Priority | Why open |
| --- | --- | --- |
| F7 | P1 | Offline sync still verifies without recording a transaction. Recording needs a business decision on offline quota and duplicate rules. The batch is now bounded and provider-checked. |
| F8 | P1 | No complete change cursor / tombstones / historical (as-of) partner contract. |
| F9 | P1 | No durable master-change outbox, webhook delivery or replay. |
| F10 (rest) | P1 | Position API "vacant" vs establishment capacity semantics; transfers still reuse the source hierarchy version id. |
| F11 | P1 | No production topology, 3M load or recovery exercise in this change. |
| F13 | P2 | Audit PII copies; correlation id reaches request logs but not every audit row. |
| F14 | P2 | OpenAPI added for the partner v1 surface (`docs/integration/openapi-v1-partner.yaml`); consumer contract tests beyond the cafeteria are not automated. |
| F20 | P2 | Hierarchy resolver still treats a future-dated published version as current. |
| F15 | P3 | OAuth2 client credentials / federation — not implemented (Sanctum tokens remain). |

## 3. Operational steps after deploy

1. `php artisan migrate --force` (additive: two tables, two columns; existing applications become `unreviewed`).
2. `php artisan api:access-review` — for each application decide **city** or **scoped** (+ grants) in System Settings → API Management. Until then an unreviewed application can still authenticate but reads no organization-bound data.
3. Bind every application that calls `/api/v1/cards/verify`, `/services/*`, `/offline-sync/*`, `/providers/*` to its service provider.
4. Grant `employees.photo_read` only to terminals that must show the holder photo.
5. Re-issue any token flagged `LEGACY_CREDENTIAL`.
6. Cafeteria system: deploy the updated `ServeEmployeeService` together with or after the EUISIS change (it requires `employee_uuid`).

## 4. Verification

- New tests: `tests/Feature/Api/MasterBackboneRemediationApiTest.php` (33), `tests/Feature/MasterBackboneDomainRemediationTest.php` (4), three API Management cases; updated `IdCardIntegrationApiTest`, `OrganizationEmployeeApiTest`, `ExternalApplicationOrganizationScopeTest` (SEC-010 evidence inverted).
- Targeted: `tests/Feature/{Api,Settings,Nfc}` + SEC-010 — 270 passed.
- Cafeteria system (own vendor, throwaway `APP_KEY`): 131 passed.
- `npm run typecheck` clean; `npm run build` OK; `node scripts/i18n-audit.mjs --strict` exit 0; Pint on changed files only.
- Full suite: see §6.
- Local PostgreSQL: migration applied (`external_applications.id` is VARCHAR(36), so the grant FK uses a string column).
- Not done: 220K-row timing of the bounded directory queries (the scale database was not re-seeded for this change); browser walk-through of the API Management form.

## 5. Re-score (same weights as the audit)

| Area | Weight | Before | After | Reason |
| --- | ---: | ---: | ---: | --- |
| Master data authority | 20 | 17 | 17 | Unchanged |
| Identity integrity | 15 | 12 | 13 | Partner contract now carries canonical UUIDs; one validity rule |
| Assignment and position | 10 | 8 | 9 | Archive guard, destination JD pinned; capacity semantics and hierarchy version reuse remain |
| API architecture | 15 | 8 | 10 | Provider routes usable by machine clients, consumer contract fixed, OpenAPI; no sync/history contract |
| Security | 15 | 6 | 11 | F1/F2/F5/F16/F17 closed with tests; OAuth2 and deployment acceptance still open |
| Events and webhooks | 10 | 1 | 1 | Unchanged |
| Scalability and availability | 10 | 5 | 5 | Not re-measured |
| Governance and documentation | 5 | 3 | 4 | Partner docs, access review |
| **Total** | 100 | **60** | **70** | |

## 6. Verdict

All three P0 blockers (F1, F3, F5) and the P1 security/contract defects F2, F4,
F6, F16, F17 are closed with automated evidence. The partner API is fit for a
**controlled, scoped partner pilot** after the operational steps in §3.

The backbone as a whole is still **not** ready for unrestricted city-wide
rollout: durable change delivery (F8/F9), offline recording (F7), production
load and recovery evidence (F11) and the remaining semantic items are open.

**PARTNER_API_CONDITIONALLY_READY_FOR_SCOPED_PILOT** ·
overall **MASTER_BACKBONE_ARCHITECTURE_NOT_READY**
