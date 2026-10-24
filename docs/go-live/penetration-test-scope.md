# Penetration test scope

Status on 24 October 2026: **plan only**. No penetration test has been performed on this release. Nothing in this document is a result.

Audience: the city's security owner, the penetration-testing team and the operations team. This document builds on the existing material and does not repeat it: [INSA testing checklist](../insa-security-testing-checklist.md), [attack surface map](../security-attack-surface.md), [production security checklist](../production-security-checklist.md), [partner API security tests](../integration/security-testing.md), [ADR 0003](../architecture/adr/0003-security-hardening-after-core-stages.md), [failure modes](../architecture/failure-modes.md).

## 1. Gate

- The penetration test is **mandatory** and is a **blocking go-live gate**. Go-live is not allowed while any P0 or P1 finding (section 6), security or business, is unresolved.
- An **independent security team** performs the test. At the very least, it must be a team separate from the development team. Nobody who wrote or reviewed the code under test may lead the test or sign it off.
- Evidence is recorded as references, and `php artisan production:readiness --strict` checks them: `GOLIVE_PENTEST_REPORT` (the report reference) and `GOLIVE_PENTEST_OPEN_P0_P1` (the count of open P0/P1 findings, which must be 0). See [go-live-gates.md](go-live-gates.md).
- The security owner signs "Security acceptance" in [core-acceptance-matrix.md](core-acceptance-matrix.md#sign-offs).

## 2. Target

| Surface | Entry points | Authentication |
| --- | --- | --- |
| Admin web application | `/employees`, `/organizations`, `/organization-units`, `/positions`, `/id-cards`, `/card-requests`, `/print-batches`, `/users`, `/roles`, `/system-settings/*`, `/audit-logs` | session; MFA for privileged roles |
| Core workspace | `/core/login`, `/core`, `/core/assignments`, `/core/integration`, `/core/audit`, `/core/settings` | session, MFA, `core.access` |
| Public card check | `/id-checker`, `/id-checker/{cardUuid}` with `send-otp` and `verify-otp`, `/verify`, and the legacy `/verify/card/{publicCardUuid}` (a 301 redirect to the checker) | none |
| Public feedback and help | `/feedback/{token}/*` (organization QR), `/service-feedback/{token}/*` (employee QR), `/public-assistant/*` | none |
| Self-registration and reset | `/register`, `/register/send-otp`, `/forgot-password`, `/reset-password/{token}` | none |
| Employee portal | `/employee/login`, `/my-portal/*` | session |
| Provider portal | `/provider/portal/*` | `provider` guard |
| Partner API | `/api/v1/*` ([openapi-v1-partner.yaml](../integration/openapi-v1-partner.yaml)) | Sanctum token (registered application or person) |
| NFC terminal API | `/api/v1/nfc/*` | application token and the NFC gate |
| Transport device API | `/api/v1/transport/*` | device credential |
| Local file serving | `GET` and `PUT /storage/{path}` (`storage.local`, private disk) | signed URL |

The following are out of scope unless agreed in writing: production, network and hosting infrastructure, third-party SMS and e-mail gateways, social engineering, physical access and denial of service.

Approach: grey-box. The team receives this document, the route list (`php artisan route:list`), the OpenAPI file and the documents linked above. Source code is available on request.

## 3. Rules of engagement

| Rule | Requirement |
| --- | --- |
| Independence | Independent security team, or at least a team separate from development. Declare any conflict of interest before starting. |
| Environment | A dedicated test environment that runs the release candidate with production settings (`APP_DEBUG=false`, MFA enforced, `ORGANIZATION_SCOPE_REQUIRE_EXPLICIT=true`). **Never production.** |
| Data | Synthetic test data only. Never use production data or any copy of it. |
| Accounts | The operations team provides the test accounts and test API applications (section 4). Do not share them. They are disabled after the retest. |
| Load | No denial-of-service, load or volumetric testing. Throttle tests use the smallest burst that shows the limit. |
| Paid services | SMS and e-mail go to the test gateway, or the SMS kill switch is on. No real messages reach real people. |
| Destructive actions | Only against test records. Database access only for 5.12, and only if agreed in writing. |
| Urgent findings | Report each P0 finding to the security owner within 24 hours. Do not wait for the report. |
| Stop | The security owner or the operations lead may stop testing at any time. Contacts are exchanged before day 1. |
| Evidence handling | Store evidence encrypted. Redact tokens, one-time codes and personal data in the report. Delete working data within 30 days after the retest is accepted. |

## 4. Test accounts and fixtures

The operations team provides all of the following on the test environment. The fixtures use organization A (with child organization A1) and organization B.

| Kind | Needed |
| --- | --- |
| Staff accounts | Super Admin; City Admin; Organizational Admin (`subtree` on A); HR Officer (`self` on A); HR Officer with **no scope record**, linked to an employee of B; HR Officer with no scope record and **no linked employee**; an account whose scope rows have **all expired**; ID Card Officer and ID Card Approver (A); Auditor (A); API Manager |
| Other people | Two employee portal accounts (one in A, one in B); provider portal users of two different providers; a Cafeteria Operator; a Transport Operator |
| Registered applications | `city`; `scoped` with `self` on A; `scoped` with `subtree` on A; `unreviewed`; one bound to provider X; one unbound; one NFC application |
| Devices and receivers | A transport device credential; a public HTTPS webhook receiver controlled by the testers |

## 5. Test areas

Each area lists what to test, the expected secure behaviour and the existing automated pre-checks in `tests/`. The pre-checks show what the development team already covers. They do not replace this test, and their results are not evidence for it.

### 5.1 Cross-organization IDOR (web and API)

| Test | Entry points | Expected secure behaviour |
| --- | --- | --- |
| Swap IDs and UUIDs to another organization's records | `employees.show`/`employees.update`, `organization-units.*`, `positions.*`, `id-cards.show` and card actions, `card-requests.*`, `print-batches.show`, `/core/assignments`, `grievances.cases.show`, `discipline.cases.show` | Refused (403 or 404). No data in the response or in the Inertia page props |
| The same through the API | `/api/v1/employees/{employee}`, `/api/v1/organizations/{organization}/*`, `/api/v1/organization-units/{unit}/*`, `/api/v1/positions/{position}`, `/api/v1/cards/{cardUuid}` | 404, the same answer as for a record that does not exist. Lists are filtered |
| Organization-level role **without a scope record** | the two HR Officer accounts without scope, on lists, detail pages and exports | Reaches only the linked employee's current organization, or nothing when no employee is linked (ADR 0003) |
| **Empty scope set** (all scope rows expired) | NFC management pages, `organization-units.index` and its create form, `users.organization-scopes.store` | Sees nothing and cannot delegate any organization: an empty set means nothing |
| Move a record into scope through the request | hidden `organization_id` fields, filters, query strings, import files | The organization is resolved on the server from the current assignment. Request values are ignored or refused |
| Exports | `service-feedback.admin.export`, provider and cafeteria exports, `situation-room.exports.download` | In-scope rows only |

- Pre-checks: `OrganizationScopeServiceTest`, `SubtreeScopeTest`, `EmptyScopeFailsClosedTest`, `GlobalRoleScopeBypassTest`, `OrganizationalAdminScopeTest`, `PrintBatchScopeTest`, `AuditLogScopeTest`, `OrganizationEmployeeApiTest`, `CoreWorkspacePagesTest`. `php artisan access:scope-gaps` lists the accounts that the ADR 0003 rule confines.
- Severity: any read or write of another organization's records is P0.

### 5.2 API scopes and organization bounds for registered applications

| Test | Entry points | Expected secure behaviour |
| --- | --- | --- |
| Missing or removed scope, unassigned endpoint, IP not allowed, suspended application | every `/api/v1/*` route | 401 `unauthenticated`; 403 `missing_scope`, `endpoint_not_allowed`, `ip_not_allowed` or `application_suspended`. A removed scope stops older tokens on their next request |
| `organization_access` set to `unreviewed`, `scoped` (`self`, `subtree`) or `city` | `/api/v1/organizations*`, `/api/v1/employees*`, `/api/v1/assignments`, `/api/v1/id-cards/verify/{token}` | `unreviewed` reads no organization-bound data. Outside the bound: 404 or `card_not_found`. A `subtree` grant follows the latest published hierarchy |
| Provider-portal person token (`provider:access`) outside the provider routes | `/api/v1/organizations/directory`, `/api/v1/employees`, `/api/v1/id-cards/verify/{token}` | Refused (F1) |
| Person tokens | any route | Never more than the person's own screens show (permission and own scope) |
| Change feed and webhook subscriptions | `/api/v1/changes*`, `/api/v1/webhook-subscriptions*` | Only the caller's own subscriptions and data inside its bound |
| Token lifecycle | `/system-settings/api-management` | Token shown once and stored hashed. Revocation takes effect at once |

- Pre-checks: `MasterBackboneRemediationApiTest`, `IdCardIntegrationApiTest`, `ExternalApplicationOrganizationScopeTest`, `OrganizationEmployeeApiTest`, `ApiManagementTest`, `OpenApiContractTest`. `php artisan api:access-review` flags risky applications.
- Severity: out-of-bound personal data is P0. A scope bypass that exposes no data is P1.

### 5.3 Provider isolation

| Test | Entry points | Expected secure behaviour |
| --- | --- | --- |
| A provider portal user reads another provider's data | `/provider/portal/cafeteria/*` (orders, menus, transactions, ledger, exports), `/provider/portal/transport/devices` | Own provider only |
| The provider guard reaches staff or employee areas | `/core`, `/my-portal`, `/system-settings/*`, admin pages | Refused |
| A bound application names another provider | `POST /api/v1/cards/verify`, `/api/v1/services/{serviceType}/authorize`, `/api/v1/services/{serviceType}/transactions`, `/api/v1/providers/{provider}/settlements/{period}` | 403 `provider_binding_missing` or `provider_binding_denied` |
| A person calls without `provider_code` | `POST /api/v1/cards/verify`, `POST /api/v1/services/{serviceType}/authorize` | 422 `provider_required` and a `ProviderApiDenied` audit row. A body `service_type` that differs from the path is refused (ADR 0003) |
| Offline sync items | `POST /api/v1/offline-sync/transactions` | Items that name another provider are refused, the batch cap is enforced and replays are de-duplicated |
| Device credentials | `/api/v1/transport/*` | A revoked or rotated device is refused. A device cannot act on another provider's trips |

- Pre-checks: `MasterBackboneRemediationApiTest` (F16, F18 and the ADR 0003 case), `CafeteriaProviderPortalSeparationTest`, `CafeteriaProviderPolicyScopeTest`, `ProviderPortalScanTest`, `ProviderPortalLoginTest`, `ProviderUserManagementTest`, `TransportDeviceApiTest`.
- Severity: cross-provider transactions or settlements are P0. A cross-provider read of non-personal data is P1.

### 5.4 QR verification privacy

| Test | Entry points | Expected secure behaviour |
| --- | --- | --- |
| Public page content | `GET /id-checker/{cardUuid}`, `/verify`, `/verify/card/{publicCardUuid}` | Before the one-time code, the page shows only that the card exists and is active. The holder's identity appears only after the holder relays the code sent to their own e-mail or phone. Never shows national ID, phone, address, salary or documents |
| Card states | the same pages with revoked, expired, replaced, suspended, lost, not-yet-issued and unknown cards | Invalid. Fails closed with no cached answer |
| Enumeration | the same pages, plus `POST /id-checker/{cardUuid}/send-otp` and `verify-otp` | An unknown card, a revoked card and a wrong code cannot be told apart (status, body or timing). Throttled: 30/min on the page, named limiters on the OTP routes. Code attempts are limited |
| QR payload | the printed QR | Only a URL that carries the public card UUID |
| Partner verification | `GET /api/v1/id-cards/verify/{token}`, `POST /api/v1/cards/verify`, `/api/v1/verifications` | `valid: true` only when the card is active, not revoked, not expired and current. Fields as listed in [id-card-verification.md](../integration/id-card-verification.md) |

- Pre-checks: `PublicIdCheckerTest`, `CardTokenSecurityTest`, `IdCardQrPayloadVersionTest`, `IdCardIntegrationApiTest` (F5, the seven card states).
- Severity: a revoked, expired or replaced card shown as valid is P0. Identity shown without the one-time code is P0. An enumeration oracle is P1.

### 5.5 File access

| Test | Entry points | Expected secure behaviour |
| --- | --- | --- |
| Employee photos | `employees.private-photo`, `/scan-photos/{employee}`, `/api/v1/employees/{employee}/photo` (signed, 5 minutes), `/service-feedback/{token}/photo`, `GET /storage/{path}` | An unsigned, altered or expired link gets 403. The principal is re-checked at serve time (404 once access is lost). `Cache-Control: private, no-store`. No public `/storage/...` photo URLs |
| Writes through the file route | `PUT /storage/{path}` | Refused without a valid signature |
| Employee documents | `/my-portal/documents/{document}/download`, `transfer-applications.documents.download` | Own or in-scope documents only |
| Evidence and attachments | `/my-portal/discipline/{case}/evidence/{evidence}`, `discipline.cases.evidence.download`, `grievances.cases.evidence.download`, `grievances.cases.letters.download`, `performance.evidence.download`, `/assessments/evidence/{evidence}`, `service-submissions.attachments.download`, `daily-activities.attachments.download`, communication attachments | Case parties and scoped officers only. An ID taken from another case is refused |
| Uploads | evidence, attachments, employee import, public feedback | Extension and content are checked. Files are stored on the private disk and never executed. Path traversal is refused |
| Exports | CSV and XLSX exports | Spreadsheet formula injection is neutralised |

- Pre-checks: `BoundaryHardeningTest` (SEC-013), `PublicUploadExtensionTest`, `CsvFormulaInjectionTest`, `MasterBackboneRemediationApiTest` (F17), `LoginCardPhotosTest`, `GrievanceHttpTest`, `DisciplinaryHttpTest`, `PerformanceWorkflowTest`, `TransferModuleTest`.
- Severity: unauthorized download of evidence, documents or photos is P0. An uploaded file that executes is P0.

### 5.6 Session and MFA

| Test | Entry points | Expected secure behaviour |
| --- | --- | --- |
| Idle timeout | any signed-in page; `/session/status`, `/session/activity` | The session ends after the configured minutes (System Settings → Security). Background polling does not keep it alive |
| MFA for privileged roles | `/mfa/challenge`, `/mfa/setup`, every MFA-guarded page | Roles in `MFA_REQUIRED_ROLES` (by default Super Admin, System Admin, City Admin, Public Service Bureau Admin and Cafeteria Admin) cannot reach guarded pages without MFA. The challenge is throttled at 5/min. Recovery codes work once |
| Session fixation | `POST /login` (also reached from `/employee/login` and `/core/login`), `POST /provider/portal/login` | The session ID changes at sign-in and again after MFA. Cookies are `Secure`, `HttpOnly` and `SameSite` |
| Logout | `POST /logout`, `POST /provider/portal/logout` | The server session is invalidated. The back button or a replayed cookie shows nothing |
| Device and session list | `/profile` (Devices), `DELETE /profile/devices/{session}`, `DELETE /profile/devices` | Lists and ends only the user's own sessions |
| Password reset and forced change | `/forgot-password`, `/reset-password/{token}`, the forced password change | The reset token works once and expires. The forced change cannot be skipped |

- Pre-checks: `SessionTimeoutTest`, `MfaFlowTest`, `MfaSettingsTest`, `ProfileDevicesTest`, `AuthenticationTest`, `PasswordPolicyTest`, `ProviderPortalLoginTest`, `SecurityHeadersTest`.
- Severity: an MFA bypass for a privileged role is P0. Session fixation or a logout failure is P1. Timeout drift is P2.

### 5.7 Privilege escalation

| Test | Entry points | Expected secure behaviour |
| --- | --- | --- |
| Assign Super Admin, or a role with permissions the actor lacks | `users.assign-roles`, `users.update`, `roles.store`, `roles.update` | Refused for everyone except Super Admin |
| Widen a scope | `users.organization-scopes.store`, `.update`, `.destroy` | A scoped admin cannot grant `citywide`, and cannot remove a user's last scope to widen that user's reach |
| Touch a Super Admin account | `users.edit`, `users.update`, `users.deactivate` | Refused for anyone who is not Super Admin |
| An employee or provider user opens admin URLs | admin routes | Refused |
| Take over an account through self-registration | `/register`, `/register/send-otp` | Knowing an employee number does not open that employee's account |
| Accounts with direct permissions and no role | `access:scope-gaps` output (`DIRECT_PERMISSIONS_UNSCOPED`) | Report any reach found beyond the account's intended organization |

- Pre-checks: `PrivilegeEscalationTest`, `RolePrivilegeEscalationTest`, `OrganizationalAdminUserManagementTest`, `RoleScopeTypeTest`, `PageAccessTest`, `SelfRegistrationTakeoverTest`, `BoundaryHardeningTest` (SBH-001).
- Severity: any vertical escalation, or a citywide grant by a scoped actor, is P0.

### 5.8 Rate limits and throttles

| Surface | Entry points | Expected secure behaviour |
| --- | --- | --- |
| Sign-in | `POST /login`, `POST /provider/portal/login` (5/min), `POST /mfa/challenge` (5/min), `/forgot-password` and `/reset-password` (5/min) | 429 or lockout after the limit. Testers check whether a new IP address or user agent resets an account's counter. If the cache is down, sign-in fails closed |
| One-time codes | `/register/send-otp`, `/id-checker/{cardUuid}/send-otp` and `verify-otp`, `/my-portal/profile/contact` (3 per 10 min) | The limits hold. The daily SMS cap and the per-recipient SMS cap stop paid sends |
| Public submissions | `/feedback/{token}/*`, `/service-feedback/{token}/*`, `/public-assistant/messages` | Named limiters return 429. One visitor cannot flood an organization |
| Partner API | `/api/v1/*` | The global `api` limiter plus a per-application limit: 429 with `Retry-After` |
| Devices and replay | `/api/v1/transport/*`, webhook replay (10/min) | Limited per device and per actor |

- Pre-checks: `RateLimitingTest`, `PublicFeedbackAbuseTest`, `PublicIdCheckerTest`, `BoundaryHardeningTest` (SBH-003, SMS caps), `TransportDeviceApiTest`.
- Severity: no limit on sign-in or one-time codes is P1, or P0 when it leads to account takeover. Other gaps are P2.

### 5.9 Replay and webhooks

| Test | Entry points | Expected secure behaviour |
| --- | --- | --- |
| Signature | deliveries to the testers' receiver | `Addis-Signature` is an HMAC-SHA256 over `t.event_id.raw_body`. The timestamp tolerance is 300 s. Two `v1` values appear only during a rotation. The secret is shown once and never logged ([webhook-security.md](../integration/webhook-security.md)) |
| Secret rotation | `integrations.subscriptions.rotate` | The old secret stops signing after the overlap window (24 h by default) |
| Callback URL (SSRF) | `integrations.subscriptions.store`, `.update`, `.test` | Private, loopback, link-local, cloud-metadata, CGNAT and IPv4-mapped IPv6 addresses are refused, both at save and at delivery. Redirects are not followed, DNS rebinding is pinned and TLS is verified |
| Idempotency keys | `POST /api/v1/services/{serviceType}/transactions`, `POST /api/v1/offline-sync/transactions` | The same key and body replay the stored response (`Idempotent-Replay: true`). A different body gets 409 `idempotency_key_reused`, a request still in progress gets 409, and a key over 128 characters gets 400. Keys are scoped per principal |
| Replay endpoints | `POST /api/v1/webhook-subscriptions/{subscription}/replay`, `integrations.subscriptions.replay` | Own subscriptions only. Throttled |
| Payload view | Integration Center event view | Only holders of `integrations.payload.view`. Each view is audited |
| Races | card issue and activate, cafeteria serve | Parallel requests produce exactly one result |

- Pre-checks: `WebhookDeliveryTest`, `IntegrationAdminTest`, `OutboxEventsTest`, `ChangeFeedTest`, `MasterBackboneRemediationApiTest` (F6), `ConcurrencyAndIdempotencyTest`, `IdCardIssuanceRaceTest`.
- Severity: SSRF to an internal address is P0. A double-spent service transaction is P0. A forged or stale event that the reference verifier accepts is P1.

### 5.10 Mass assignment

| Test | Entry points | Expected secure behaviour |
| --- | --- | --- |
| Forged fields in forms and JSON | `employees.store`, `employees.update`, `/my-portal/profile`, `card-requests.store`, discipline, grievance and performance forms, `users.update`, the provider portal profile | Fields such as `organization_id`, `status`, `employee_number`, `national_id`, role and scope fields, card `status`, `is_current` and `revoked_at`, workflow state and scores are ignored or refused |
| Writes through the API | every `POST` under `/api/v1/*` | Machines never write master data. They record service transactions only |

- Pre-checks: `EmployeeSelfServiceTest`, `DailyActivityModuleTest` (case 29), `PerformanceWorkflowTest` (case 54), `DisciplinaryHttpTest`, `MachineWriteBoundaryTest`, `BackboneBoundaryTest`.
- Severity: changing a status, organization, role or card validity is P0. Other fields are P2.

### 5.11 Sensitive-data exposure

| Test | Entry points | Expected secure behaviour |
| --- | --- | --- |
| Personal fields in responses | Inertia page props (the `data-page` JSON), API responses, exports | National ID, phone, address and photo storage path appear only where the role needs them, and are masked elsewhere |
| Error pages | forced 404, 419 and 500 responses; malformed input | No stack trace, SQL, file paths or versions (`APP_DEBUG=false`) |
| Logs | application logs and `api_request_logs` | No passwords, one-time codes, tokens, national IDs or request bodies |
| Audit log | Audit Center, `/audit-logs`, `id-cards.export.audit` | One-time codes, tokens and national IDs are redacted at any depth |
| Headers | all responses | HSTS, CSP, `X-Content-Type-Options` and frame protection are present. No wildcard CORS origin with credentials. Trusted proxies are correct |

- Pre-checks: `AuditLogRedactionTest`, `SensitiveLoggingTest`, `NationalIdHashTest`, `ErrorHandlingTest`, `SecurityHeadersTest`, `TrustedProxiesTest`, `SharedBrandingPropTest`, `CoreReadContractsTest`.
- Severity: bulk exposure of national IDs or contact details is P0. A single field exposed to a staff role is P1. Version banners are P3.

### 5.12 Audit-log tamper evidence (needs written agreement for database access)

| Test | Entry points | Expected secure behaviour |
| --- | --- | --- |
| Change, add or delete rows of a sealed day; alter a seal; break the chain | direct database access on the test environment, then `php artisan audit:verify` | Reported as `MODIFIED` or `CHAIN_BROKEN`. Seals cannot be recomputed without `APP_KEY` |
| Change audit rows through the application | Audit Center, `/audit-logs`, every route | No route edits or deletes audit rows |
| Documented limit | the current day's rows | These rows stay unsealed until the next run (00:20, ADR 0003). Report a finding only if it reaches beyond that window |

- Pre-checks: `AuditSealAndScopeGapsTest`, `AuditLogScopeTest`.
- Severity: an undetected change to a sealed day is P0.

## 6. Severity and priority

| Priority | CVSS v3.1 | Matrix severity | Meaning |
| --- | --- | --- | --- |
| P0 | Critical (9.0–10), or High (7.0–8.9) that is exploitable | 1 | Wrong access across organizations, wrong card validity, data loss, or an exploitable Critical/High finding |
| P1 | High with a workaround that is still unsafe | 2 | Must be fixed before go-live |
| P2 | Medium (4.0–6.9) | 3 | Fixed, or accepted in writing by the security owner with an owner and a fix date |
| P3 | Low or informational | 4 | Tracked |

The tester's CVSS score is the starting point. The security owner sets the final priority, and while it is disputed the higher priority applies.

## 7. Deliverables

| Deliverable | Content |
| --- | --- |
| Kick-off record | Confirmed scope, accounts, window, contacts and the independence statement |
| Urgent notice | Each P0 finding within 24 hours |
| Report | An executive summary; the method; what was and was not tested in each of areas 5.1–5.12; and for each finding: an ID, the CVSS v3.1 vector and score, the priority, the affected route, steps to reproduce on test data, redacted evidence, the impact and a recommendation |
| Retest report | The same finding IDs on the fixed build, each marked fixed, not fixed or accepted |
| Reference | The final report's document number and version, for `GOLIVE_PENTEST_REPORT` |

## 8. Exit criterion

- No open Critical or High finding (P0 or P1). Go-live is not allowed while any P0 or P1 security or business defect is unresolved.
- Every Medium (P2) finding is fixed, or the security owner accepts it in writing.
- The retest ran on the release candidate that goes live, or on a build that differs from it only by retested fixes.
- `GOLIVE_PENTEST_REPORT` holds the final report reference and `GOLIVE_PENTEST_OPEN_P0_P1` is 0, so `php artisan production:readiness --strict` passes these two checks.
- The security owner signs "Security acceptance" in the acceptance matrix.

## 9. Findings and the defect register

- Each finding is added to the defect register in [core-acceptance-matrix.md](core-acceptance-matrix.md#defect-register) as `D-n`, with these fields: severity (1–4), priority (P0–P3), a one-line description without exploit detail, the report's finding ID, and the state (open; fixed, awaiting retest; closed; or accepted, which is allowed for P2 and P3 only and names the signer).
- Fixes follow normal change control. Where possible, each fix gets an automated regression test, which then joins the pre-check list for its area.
- While a P0 or P1 finding is open against a criterion (usually A4, A5, A16 or A17), the security owner marks that criterion as blocked in the matrix.
