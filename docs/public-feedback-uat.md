# Public Feedback Management — UAT record

Amharic: የተገልጋይ አስተያየት አስተዳደር — የተጠቃሚ ተቀባይነት ሙከራ

Executed 2026-10-12 on the development database (synthetic data; no
production data). Automated scenarios are Pest feature tests that run on
SQLite in the suite; the PostgreSQL-only behaviours (CHECK constraints,
partial unique indexes, the `text` widening) were applied to the PostgreSQL
development database and probed through `pg_constraint` / `pg_indexes`,
because the PostgreSQL test database cannot be built on this machine
(Application Control blocks `pg_trgm.dll`). Browser scenarios were walked
through in the Amharic and English interfaces at desktop width, dark theme,
with the built assets on the local server.

Legend: **PASS** — behaved as expected · **FAIL** — did not · **N/A** —
not applicable / outside scope.

Numbering follows the brief's required test list (§117–122).

## 1. Employee QR (§117)

Evidence: `tests/Feature/PublicFeedback/TargetResolverTest.php`,
`TargetIntegrityTest.php`, `tests/Feature/ServiceFeedbackPublicTest.php`.

| # | Scenario | Evidence | Result |
|---|---|---|---|
| 1 | Valid active employee card QR resolves the employee | resolves an active employee feedback token to an employee target with its service context | PASS |
| 2–4 | Feedback, suggestion and complaint target the scanned employee | lets a client submit a rating and comment; records a suggestion for the qr employee; issues a non-sequential complaint reference and stores approved attachments privately | PASS |
| 5–6 | Browser cannot change the employee target; forged `employee_target_id` ignored | keeps the employee flow attached to the scanned employee when an organization target is forged (OrganizationPublicFlowTest) | PASS |
| 7 | Invalid card rejected | returns a safe generic page for an unknown token | PASS |
| 8 | Revoked / inactive card follows the service-block rule | returns the same generic page for a revoked token; rejects an inactive feedback qr for every new submission type | PASS |
| 9 | Card reprint does not change the QR target | keeps the feedback token stable when the employee photo changes / details are updated | PASS |
| 10 | Transfer does not change historical submission context | keeps the employee target when the employee later transfers; keeps the feedback token stable when the assignment changes | PASS |

Browser: scanned-URL landing in Amharic (three options), complaint form with
category, receipt with `PSC-2026-000003`, acknowledgement text — PASS.

## 2. Organization QR (§118)

Evidence: `OrganizationFeedbackQrTest.php` (13), `OrganizationPublicFlowTest.php` (10).

| # | Scenario | Result |
|---|---|---|
| 11–12 | Authorized user enables the QR; exactly one active code is minted | PASS |
| 13 | Reopening the page does not regenerate the token | PASS |
| 14 | Institution rename does not rotate the QR | PASS |
| 15 | The QR resolves the correct institution | PASS |
| 16–18 | Feedback, suggestion and complaint target the scanned institution (reference and attachment on the complaint) | PASS |
| 19 | Browser cannot change the institution target | PASS (ignores any target or type the browser tries to supply) |
| 20 | Raw institution ID is not public authority | PASS (`/feedback/1` → unavailable; HardeningTest) |
| 21 | Archived / inactive institution behaves safely | PASS (unavailable page, nothing stored) |
| 22 | Revoked QR no longer accepts submissions | PASS |
| 23 | Rotation invalidates the previous token | PASS (never reuses a revoked token) |
| 24 | Historical submissions keep the original institution target | PASS (snapshots; §62 guard in ReferralTest) |

Browser: institution QR page (enable, rotate with reason, print/export),
`/feedback/{token}` landing and complaint in Amharic — PASS.

## 3. Database constraints (§119)

Evidence: `TargetIntegrityTest.php`, `OrganizationFeedbackQrTest.php`; PostgreSQL probes on the development database.

| # | Scenario | Result |
|---|---|---|
| 25–28 | Employee target requires `employee_id` and rejects an organization target, and the converse | PASS (PHP guard on every driver; `esf_target_integrity_chk` on PostgreSQL) |
| 29 | Invalid source/target combinations rejected | PASS (`esf_source_target_chk`) |
| 30 | Duplicate active institution QR prevented | PASS (`org_feedback_qr_one_active` partial unique index) |

## 4. Types (§120)

Evidence: `OrganizationPublicFlowTest.php`, `PublicFeedbackCategoryTest.php` (6), `PublicFeedbackHardeningTest.php`.

| # | Scenario | Result |
|---|---|---|
| 31–33 | Stored as `feedback` / `suggestion` / `complaint` | PASS |
| 34 | Type cannot be forged outside the allowed set | PASS (routes constrain `{type}`; forged `type` field ignored) |
| 35 | Type-specific category validation | PASS (refuses a category that does not apply to the submission) |

## 5. Public security (§121)

Evidence: `PublicFeedbackHardeningTest.php` (5), `PublicFeedbackAbuseTest.php` (4), `OrganizationPublicFlowTest.php`, `PublicFeedbackVisibilityTest.php` (5).

| # | Scenario | Result |
|---|---|---|
| 36 | IDOR impossible through public target IDs; every management route answers 403 to a foreign-scope admin and to the target employee | PASS |
| 37 | XSS payload stored and rendered escaped on inbox, detail, report and dashboard | PASS |
| 38 | CSRF on every POST (Laravel middleware; the public forms post with the session token) | PASS (framework) |
| 39 | Rate limiting per QR code and per address, thresholds from the policy, on the shared cache store | PASS |
| 40 | Duplicate POST does not create a duplicate (duplicate guard + one-time form token) | PASS |
| 41 | Unsafe attachments refused (double extension, script names, executable content, oversized, too many) | PASS |
| 42 | Private attachment cannot be accessed directly; listing and download take the evidence grant | PASS |
| 43–44 | Enumeration blocked; internal IDs not exposed (UUID routes, 64-hex tokens, raw token hidden from JSON) | PASS |

## 6. Domain boundary (§122)

Evidence: `PublicFeedbackReferralTest.php` (4), `PublicFeedbackHardeningTest.php`.

| # | Scenario | Result |
|---|---|---|
| 45–47 | A public complaint creates no grievance, no discipline case and no appeal; a referral is a request record only | PASS |
| 48 | Feedback does not alter EPMS (submission no longer calls the performance sync) | PASS (existing behaviour, `docs/employee-feedback-security.md`) |
| 49 | Suggestions and complaints stay on one table with no sibling records in other domains | PASS |

## 7. Workflow, routing, visibility, reports, notifications (brief §45–57, §81–89)

| Area | Evidence | Scenarios | Result |
|---|---|---|---|
| Type-specific workflow, immutable history, permissions per transition, target employee excluded | `PublicFeedbackWorkflowTest.php` | 10 | PASS |
| Routing rules precedence, UNROUTED + administrator alert, scope on rules, assignment history, my queue | `PublicFeedbackRoutingTest.php` | 9 | PASS |
| Policy settings: master switch, per-type switches, attachments, identity modes, validation of contact | `PublicFeedbackSettingsTest.php` | 8 | PASS |
| Identity masking, encrypted contact with legacy read-through, evidence grant, target-type grants | `PublicFeedbackVisibilityTest.php` | 5 | PASS |
| Dashboard counts, submission report breakdowns and filters, queued formula-safe export, institution QR list, sidebar group | `PublicFeedbackReportsTest.php` | 5 | PASS |
| Handler / role / unit notices, resolution notice, mail without narrative, acknowledgement by e-mail only when allowed | `PublicFeedbackNotificationTest.php` | 5 | PASS |
| Investigation subjects without retargeting; controlled referral request and decision; involved employees excluded | `PublicFeedbackReferralTest.php` | 4 | PASS |
| Localised public validation messages | `ServiceFeedbackLocalizationTest.php` | 3 | PASS |
| Existing employee QR, rating inbox, reports, exports, moderation | `ServiceFeedbackPublicTest.php`, `ServiceFeedbackAdminTest.php` | 89 | PASS |

Browser walkthrough (Amharic, then English): complaint inbox with the
unrouted banner and status tabs → detail → assign handler dialog → change
status → history timeline; routing rules page → add rule → second complaint
auto-assigned; settings page → identity mode identified → public form shows
required fields → back to optional; dashboard "public submissions" cards;
submission reports → request CSV export → queue worker → download link;
organization QR list; complaint detail → referral requested → subject named.
All PASS. Not exercised in the browser: the employee search inside the
subjects panel, because the existing `/lookup/employees` endpoint fails on
this machine for the same `pg_trgm.dll` reason (it is covered by its own
tests; the subject was named through the service instead).

## 8. Run of record

See `docs/public-feedback-readiness.md` §4 for the full-suite numbers,
type-check, build, key parity and dependency audits.
