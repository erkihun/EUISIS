# API and integration readiness

Companion to the [master backbone audit](addis-one-master-backbone-audit.md). Audit date 19 October 2026, HEAD `cc4a44f6`. Finding IDs (F1–F20) and evidence references (E1–E23) match the main report.

**Verdict: PARTIAL.** A versioned, read-only, presenter-based v1 API with independent machine identities exists and is worth keeping. It is not safe for broad partner rollout until the P0 authorization, verification and consumer-contract defects are fixed.

## 1. Current API inventory

Source: `php artisan route:list --json` (1,504 routes; 28 under `/api/v1`), `routes/api.php`, the v1 controllers and `App\Services\Api\OrganizationDataPresenter`. All 28 v1 routes run `api, auth:sanctum, throttle:api`.

Middleware key: **XAG** = `ExternalApplicationGate` (active check, IP allowlist, endpoint grant, per-application limit, `api_request_logs`); **EPSS** = `EnsureProviderServiceScope`; **NAG** = `NfcApplicationGate`; **Idem** = `EnforceIdempotencyKey`. PII levels are engineering proposals, not legal classifications.

| Method | Path | Controller | Guard | Scope | Response | PII |
| --- | --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/organizations` | `OrganizationDirectoryController` | XAG | `reports.read_limited` | ad hoc list, ≤500 | none |
| GET | `/api/v1/organizations/directory` | `OrganizationApiController@index` | XAG | `organizations.read` | presenter, paged | none |
| GET | `/api/v1/organizations/{organization}` | `OrganizationApiController@show` | XAG | `organizations.read` | presenter | none |
| GET | `/api/v1/organizations/{organization}/units` | `@units` | XAG | `organization_units.read` | presenter | none |
| GET | `/api/v1/organizations/{organization}/positions` | `@positions` | XAG | `positions.read` | presenter | none |
| GET | `/api/v1/organizations/{organization}/employees` | `@employees` | XAG | `employees.basic_read` | presenter, paged | medium — roster |
| GET | `/api/v1/organizations/{organization}/structure` | `@structure` | XAG | `organization_structure.read` | org + units + positions + occupant summary | medium — who holds which seat |
| GET | `/api/v1/organization-units` | `OrganizationUnitApiController@index` | XAG | `organization_units.read` | presenter, paged | none |
| GET | `/api/v1/organization-units/{unit}` | `@show` | XAG | `organization_units.read` | presenter | none |
| GET | `/api/v1/organization-units/{unit}/positions` | `@positions` | XAG | `positions.read` | presenter | none |
| GET | `/api/v1/organization-units/{unit}/employees` | `@employees` | XAG | `employees.basic_read` | presenter, paged | medium — roster |
| GET | `/api/v1/positions` | `PositionApiController@index` | XAG | `positions.read` | presenter, paged | none |
| GET | `/api/v1/positions/{position}` | `@show` | XAG | `positions.read` | presenter | none |
| GET | `/api/v1/employees` | `EmployeeDirectoryApiController@index` | XAG | `employees.basic_read` | presenter, paged | medium — names, gender, status, placement |
| GET | `/api/v1/employees/{employee}` | `@show` | XAG | `employees.basic_read` | presenter | medium |
| GET | `/api/v1/employees/{employee}/assignment` | `@assignment` | XAG | `employee_assignments.read` | presenter | low |
| GET | `/api/v1/employees/{employee}/service-eligibility` | `IdCardVerificationApiController@eligibility` | XAG | `service_eligibility.check` | ad hoc | low — **500 for application tokens (F4)** |
| GET | `/api/v1/id-cards/verify/{token}` | `IdCardVerificationApiController@show` | XAG | `id_cards.verify` | ad hoc card + employee + org + position | high — name, number, photo link (F17); **revoked QR valid (F5)** |
| GET | `/api/v1/employees/{employee}/entitlements` | `EmployeeEntitlementController` | EPSS + `authorize('view')` | `service_eligibility.check` | `EntitlementResource[]` | low |
| POST | `/api/v1/cards/verify` | `CardVerificationController` | EPSS | `id_cards.verify` | `VerificationResultResource` | low |
| POST | `/api/v1/services/{serviceType}/authorize` | `ServiceAuthorizationController` | EPSS | `service_eligibility.check` | `VerificationResultResource` | low |
| POST | `/api/v1/services/{serviceType}/transactions` | `ServiceTransactionController` | EPSS + Idem | `service_transactions.create` | `VerificationResultResource` | low (F19) |
| POST | `/api/v1/offline-sync/transactions` | `OfflineSyncController` | EPSS + Idem | `service_transactions.create` | raw verify arrays | low — **does not record; unbounded; provider scope skipped (F7, F16)** |
| GET | `/api/v1/providers/{provider}/settlements/{period}` | `ProviderSettlementController` | EPSS | `reports.read_limited` | `{provider_id, period, settlement_amount}` | none — **period ignored (F18)** |
| POST | `/api/v1/nfc/challenges` | `NfcController@challenge` | NAG + XAG | `nfc.verify` | challenge | none |
| POST | `/api/v1/nfc/verify` | `NfcController@verify` | NAG + XAG | `nfc.verify` | `NfcVerificationResource` | low |
| POST | `/api/v1/nfc/service-eligibility` | `NfcController@eligibility` | NAG + XAG | `nfc.service_eligibility` | `NfcVerificationResource` | low |
| POST | `/api/v1/nfc/service-transactions/verify-and-record` | `NfcController@record` | NAG + XAG | `nfc.service_transactions.create` | `NfcVerificationResource` | low |

Not partner APIs: `GET /api/organization-unit-types/options` (session, admin UI) and the `communication/api/*` routes (session SPA endpoints for internal chat, high PII, behind `admin.access` and `communication.enabled`). The public card checker is a web route (`/verify/card/{publicCardUuid}` → OTP-gated `PublicIdCheckerController`) and is correctly separate from the partner verification endpoint.

## 2. Readiness by requirement

| Area | Status | Evidence / reason |
| --- | --- | --- |
| Versioning | COMPLIANT (path) / PARTIAL (lifecycle) | Every partner route is under `/api/v1`. No deprecation headers, sunset policy or schema freeze. |
| Resource layer | PARTIALLY_COMPLIANT | Master reads go through `OrganizationDataPresenter` (explicit fields; national ID, phone, email, salary, documents excluded). Verification/eligibility and settlement build ad hoc arrays. No full Eloquent model dumps found. |
| Stable identifiers | COMPLIANT for masters | UUIDv7 primary keys (`HasUuidPrimaryKey`) on organization, unit, position, employee, assignment, card; not fillable. Immutability is a convention, not enforced. Verification omits the employee UUID (F3). |
| Need-to-know responses | PARTIAL | Directory excludes sensitive fields; but `employees.basic_read` returns embedded assignment data, gender, and city-wide rosters; structure endpoint lists occupants. |
| Authorization | NON_COMPLIANT | F1: `EnsureApiScope` accepts `*` and `provider:access` for every scope; `ExternalApplicationGate` passes non-application principals; directory controllers call no policy. Reproduced: a plain user with only a `provider:access` token reads any employee. |
| Organization scope for partners | NOT_IMPLEMENTED | `ExternalApplication` has only free-text `owner_institution`; every grant is city-wide (F2). Existing SEC-010 tests assert this. |
| Machine identity | PARTIALLY_COMPLIANT | `ExternalApplication` + Sanctum tokens: independent credentials, endpoint grants (deny by default), suspension, IP allowlist, per-application limit, request log, soft-delete offboarding that revokes tokens and grants. Provider-service routes do not accept it (F16). |
| OAuth 2.0 client credentials | NOT_READY | No Passport or other authorization server in `composer.json`; no issuer/audience model. Not a defect for a bounded Sanctum deployment. |
| Scopes | PARTIAL | `App\Enums\ApiScope` already defines least-privilege scopes; enforcement is weakened by the wildcard (F1). Application tokens can only be issued with enum values. |
| Shared token anti-pattern | COMPLIANT | No single global partner token found; each application gets its own tokens. The separate cafeteria app uses one configured token for itself, which is correct. |
| Partner revocation | COMPLIANT | Token revoke, application suspend and soft-delete each act on one application. Effective-grant tests after scope edits are recommended. |
| Secret rotation | PARTIAL | A new token can be issued before the old one is revoked (overlap). Default Sanctum expiry 8 h; no automated refresh flow. Webhook secrets: NOT_APPLICABLE (no webhooks). |
| Rate limiting | PARTIAL | `api` limiter 120/min keyed per principal/IP plus per-application limiter; backed by the default cache store (database by default, file locally). Distributed correctness needs a shared store. |
| Idempotency | NON_COMPLIANT for writes | `EnforceIdempotencyKey` is optional, cache-only, non-atomic, stores only 2xx, no body fingerprint (F6). Service-transaction provider reference is DB-unique, which helps. |
| Error contract | PARTIAL | Six different error shapes (see audit §M). No stack traces on API routes. |
| Request correlation | PARTIAL | `RequestCorrelationId` accepts/echoes `X-Request-ID`; audit writer and `api_request_logs` do not store it. CORS does not allow the header. |
| API audit | PARTIAL | `api_request_logs` stores application, route template, method, IP, status, failure reason, timestamp — no bodies (good), no latency/token/correlation ID; not written for EPSS routes or user tokens. |
| Pagination | COMPLIANT (bounded) / PARTIAL (sync) | Page size capped at 100; offset pagination only; no cursor. |
| Incremental sync | PARTIAL | `updated_after` filter exists (`FiltersOrganizationData`), but nested changes do not touch parent `updated_at`, soft-deleted org/unit/position rows vanish, no tombstones, no high-watermark (F8). |
| Historical reads | NOT_IMPLEMENTED | No as-of API for assignment, structure or JD. Data partly supports it (assignment intervals, hierarchy versions); transfer does not pin JD and copies the old hierarchy version (F10). |
| Freshness metadata | PARTIAL | ISO 8601 `updated_at`; assignment `effective_from/to`; no ETag, record version or hierarchy version in responses. |
| Delete / archive semantics | PARTIAL | Status fields for active/inactive; soft-deleted records disappear instead of appearing as archived/superseded. |
| Localization | COMPLIANT | Explicit bilingual fields (`name_en`, Amharic name) rather than locale-dependent output for master data. |
| Calendar / timezone | COMPLIANT | Gregorian ISO dates/timestamps in API; Ethiopian calendar stays in the UI. App timezone `Africa/Addis_Ababa`. `updated_after` accepts any Carbon-parsable string (validate strictly ISO). |
| Media URLs | NON_COMPLIANT | Partner photo link is an unsigned public `/storage/` URL (F17). |
| OpenAPI / data contracts | NOT_IMPLEMENTED | No OpenAPI/JSON Schema. Markdown contract (`docs/api-organization-employee-v1.md`) and the DB endpoint catalog (`ApiEndpointDefinition`) exist and are a good base. |
| Contract tests | NOT_IMPLEMENTED | The cafeteria consumer test mocks an invented `employee_id` the provider never sends (F3) — proof that independent mocks drift. |
| Generic CRUD risk | COMPLIANT | No partner `POST/PATCH /employees` or similar master write endpoint. Writes are domain commands (service transactions, NFC). |
| Controller thinness | PARTIAL | Master read controllers delegate to presenter/filters; `ServiceTransactionController` and the eligibility controller hold lookup logic that belongs in actions. |
| Gateway readiness | RECOMMENDED_BEFORE_EXTERNAL_ROLLOUT | Stateless bearer auth, versioned paths, request IDs and per-client limits exist; trusted-proxy config present. A gateway cannot fix F1/F3/F5. |
| Database coupling | COMPLIANT | The only external app (`external-apps/cafeteria-system`) uses its own database and HTTP; its `.env.example` forbids pointing at the main database. Infrastructure DB grants were NOT_VERIFIED. |

## 3. Identity resolution and identifier strategy

| Identifier | Purpose | Use in integrations |
| --- | --- | --- |
| Database primary key (UUIDv7 `id`) | Internal row identity **and** the stable machine key | Publish as `id` (already done). Promise immutability and non-reuse in the contract. Do not add a second UUID column just to rename it. |
| `employee_number` | Human/business identifier, unique | Display, search and human reconciliation. Satellites may keep it as a snapshot, but should also store `employee.id`. Not the only machine key. |
| `public_card_uuid` | Opaque QR / card-verification reference | Card verification only. Stable across reprint; replaced only by explicit replacement/rotation. Never regenerate en masse. |
| `card_number` | Printed card serial | Display and support. |
| `national_id` | Restricted personal attribute | Never an integration key or response field. Stored encrypted with an HMAC lookup hash. |
| `users.id` (bigint) | Authentication account | Internal only; not an integration identity. |

Recommended lookup order for a partner: `employee.id` (UUID) → `public_card_uuid` via the verification endpoint (returns `employee.id` once F3 is fixed) → `employee_number` only for human-initiated lookups.

## 4. Example partner contracts

| Partner | Needs | Should not get | Pattern | Readiness |
| --- | --- | --- | --- | --- |
| Cafeteria | Card validity, employee active, organization, eligibility decision, `employee.id` | Directory, assignment history, salary, performance, discipline | Real-time verification + domain eligibility call; satellite owns transactions | NOT_READY until F3, F4, F5 are fixed |
| Transport | Same as cafeteria plus route eligibility from the transport domain | Same | Real-time verification + eligibility; card-revocation events later | PARTIAL (internal module works; no external contract yet) |
| Tribunal / case system | Employee and organization lookup by UUID; current assignment at case date | Card data, service data | Real-time lookup; as-of assignment read later | NOT_IMPLEMENTED (no external tribunal; needs historical API) |
| Payroll (future) | Richer employment, grade, assignment history | — | Separately governed contract, incremental sync with reconciliation | NEEDS_POLICY_DECISION |
| Attendance (future) | `employee.id`, current assignment, unit | Personal attributes | Incremental sync + status/assignment events | NOT_IMPLEMENTED |
| Training (future) | `employee.id`, position/occupation, organization | Card, service data | Incremental sync | NOT_IMPLEMENTED |

## 5. Recommended v1 shape (conceptual, not implemented)

Keep every existing path and field. Add rather than rename.

- **Organizations / Units**: existing reads, plus `hierarchy_version_id`, `effective_from`, `status` including `archived`, and a `/changes` cursor feed with tombstones.
- **Positions**: existing reads, plus `capacity`, `occupied_count`, `available_slots` with an approved definition (replacing the ambiguous `vacant`, kept for compatibility), grade/occupation references.
- **Employees (basic)**: `id`, `employee_number`, bilingual names, `employment_status`, `organization_id`, `updated_at`. Move embedded assignment detail behind `employee_assignments.read` in a new field set, keeping old fields until consumers migrate.
- **Assignments**: current assignment; later `?as_of=YYYY-MM-DD` once transfer pins JD/hierarchy context.
- **Identity verification**: `card_valid`, `employee_active`, `employee.id`, `card.public_card_uuid`, approved display name, signed short-lived photo link. Eligibility stays a separate domain call returning `eligible` + `reason_code`.
- **Reference data**: organization types, unit types, grade levels, occupations, professions, position statuses — with provenance; no fabricated ISCO/ISIC/CPC codes.
- **Commands (future)**: `POST /onboarding/hires` for an external recruitment system — idempotent, audited, validated, creating employee + assignment through `RegisterEmployeeAction`. No generic CRUD.

## 6. Recommended scopes

Keep the existing `ApiScope` names that consumers already use: `organizations.read`, `organization_units.read`, `positions.read`, `organization_structure.read`, `employees.basic_read`, `employee_assignments.read`, `id_cards.verify`, `service_eligibility.check`, `service_transactions.create`, `reports.read_limited`, `nfc.*`. Add later, only when a partner needs them: `reference_data.read`, `master_changes.read` (cursor/tombstones), `onboarding.hires.create`. Bind each grant to an organization subtree and an approved field set. Remove `provider:access` and `*` as aliases for these scopes (F1). A cafeteria or transport client gets `id_cards.verify`, `service_eligibility.check` and its transaction scope — never `employees.basic_read`, performance, discipline or salary data.

## 7. Security tests run

| Test | Result |
| --- | --- |
| Plain user token with only `provider:access` → `GET /api/v1/employees/{id}` | **200** with employee data (F1 reproduced) |
| Active card with `qr_status=revoked` → partner verify | **`valid: true`**; response has no employee UUID (F5, F3 reproduced) |
| Registered application with `service_eligibility.check` → eligibility | **TypeError** → 500 (F4 reproduced) |
| Malformed card UUID on SQLite | 404; on PostgreSQL the bound UUID cast rejects it — SQLite tests do not prove PG parity |
| Wrong organization | NOT_APPLICABLE: no organization binding exists to violate (F2) |
| Rate-limit exhaustion | NOT_VERIFIED in this audit; existing `tests/Feature/Security/RateLimitingTest.php` covers the limiter |

Tests: `docs/audits/BackboneBoundaryEvidenceTest.php` (4 passed). These assert the **unsafe current behavior**; invert them into acceptance tests when fixing.
