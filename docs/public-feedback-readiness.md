# Public Feedback Management — production readiness gate

Amharic: የተገልጋይ አስተያየት አስተዳደር — የምርት ዝግጁነት በር

Assessed 2026-10-12 against the brief "Unified public feedback, suggestion &
complaint management system" (digest: `docs/public-feedback-brief-digest.md`)
and the follow-up QA brief (plan `docs/superpowers/plans/2026-10-12-public-feedback-qa-gate.md`),
after the feature commits `b060d0c`…`84d5fe5`, the QA commits `17640dd`,
`4c206d1`, `c244daf`, `6213be0` and this documentation commit. The product was
rebranded to Addis One (AA-IEMSS) on the same day (`docs/branding.md`); the
public pages carry the new brand and the scanned target stays the primary
identity. Architecture: `docs/public-feedback.md`. Security and the role
matrix: `docs/public-feedback-security.md`. Routing and the four relations:
`docs/public-feedback-routing.md`. Institution QR operations:
`docs/organization-feedback-qr.md`. UAT record: `docs/public-feedback-uat.md`.
Load test: `docs/public-feedback-load-testing.md`.

## Verdict: CONDITIONAL READY

The module is complete, proven by tests at every boundary the brief names,
measured under load, and documented. It may go live once the conditions in
§3 are met; none of them is a code change in this module.

## 1. What the QA gate found and fixed

| Finding | Severity | Fix | Proof |
| --- | --- | --- | --- |
| Institution-targeted ratings could not be opened by an administrator without an employee record (`null === null` passed the "you are the target" guard) and were hidden from employee-linked users (`employee_id != ?` drops NULL) | P1 | null-safe guard, `whereNull or !=` | `PublicFeedbackMatrixTest` |
| Hard-deleting an employee cascaded through their public submissions | P1 | foreign key restricts (PostgreSQL) | `PublicFeedbackContextTest` |
| Landing page resolved the token twice and re-fetched the employee number (16 queries) | P2 | scans counted on the resolved record; 13 queries | `PublicFeedbackContextTest` |
| A complaint whose matching routing rule named a handler that no longer existed was **refused with a validation error and lost** | P0 | routing walks the matching rules, skips an unusable one with a warning naming the rule id, falls back to UNROUTED | `PublicFeedbackRoutingTest` |
| The policy settings page rendered a prop named `settings`, shadowing the shared branding prop | P1 | renamed `feedbackSettings` | `SharedBrandingPropTest` |
| The institution-QR permission names were built by concatenation, invisible to the permission audit | P2 | spelled out | `RbacSeedingTest` |
| A storage or database error inside the public submission surfaced as HTTP 500 | P1 | rolled back, logged by class and SQLSTATE only, citizen returned to the form with a message, files not re-sent | `PublicFeedbackResilienceTest` |
| A database that only ran `migrate` has no role holding the module's permissions (every assignment 403 on the scale rig) | P1 (deploy) | documented deploy step; `production:readiness` warns | `PublicFeedbackOperationsTest` |

## 2. What is proven

- **Six combinations** (employee / institution × feedback / suggestion /
  complaint) end to end: target, source, type, routing outcome, history,
  receipt, permissions and scope.
- **QR stability**: the employee token survives position, unit and
  institution changes, suspension, new categories, services, rules and
  workflow activity; the institution token survives rename, branding,
  structure version, administrator, service, category and rule changes;
  rotation revokes, never reuses, keeps history; **the rebrand changed
  neither token nor URL**.
- **Tampering**: every forged target, type, source, status, timestamp,
  reviewer and reference field is ignored; form tokens cannot be replayed
  against another target; a double POST stores once; grievance and appeal
  are not types; sequential ids are never accepted; XSS is escaped
  everywhere.
- **Boundaries**: no public action writes a grievance, tribunal,
  disciplinary or KPI row; the opt-in KPI source reads ratings only; the
  employee record is byte-for-byte unchanged by resolving and referring a
  complaint; the target employee cannot act on their own submission; a
  foreign-scope handler gets 403 and totals of 0.
- **Failure behaviour**: storage failure → rollback, no receipt, no file,
  no narrative in the log; notification failure → submission stands;
  queue absent → export visibly queued; every public response carries
  `X-Request-ID`; the log never receives narrative, contact or QR secret.
- **Data quality and operations**: `feedback:data-quality [--repair]`
  (deterministic repairs only; NEEDS_DATA_REVIEW for the rest; nothing
  retargeted) and the `production:readiness` section.
- **Load** (`docs/public-feedback-load-testing.md`): 2–3 req/s per
  single-threaded PHP worker for this mix, 22 → 49 req/s when tripling
  nodes with database-backed shared stores, degradation by queueing with
  ~0 % errors, PostgreSQL at most 9 active queries, queue backlog ≤ 6 with
  one worker, a 100 scans/s burst on one poster with no errors. PostgreSQL
  CPU and Redis were not measured; no capacity claim is made.

## 3. Conditions before go-live

1. **Grant the permissions** (deploy step, not a migration): run the role
   seeders or compose a handler role with at least `complaints.view`,
   `complaints.review`, `assign`, `reassign`, `resolve`, `close` and both
   target-type grants; one city-level role with `manage_routing`,
   `manage_categories`, `settings.manage` and the institution QR grants;
   `view_submitter_identity`, `view_sensitive_attachments` and
   `refer_discipline` only where policy names the role. `rbac:report
   --strict` and the readiness gate report the drift.
2. **Decide the NEEDS_DECISION items** in `docs/public-feedback.md` §9 or
   accept the safe defaults in writing — on day one: identity mode
   (optional), e-mail acknowledgement (off), throttle thresholds (3 per
   code, 8 per address per ten minutes), no CAPTCHA, no public tracking, no
   malware scanner (`scan_status` stays `not_scanned`), no retention rule
   (nothing is deleted), who opens a disciplinary case after an accepted
   referral (the module never does).
3. **Operate it**: a queue worker on `notifications` and `default`;
   `CACHE_STORE=redis` (or database) shared across nodes so the throttle,
   one-time form tokens and settings are shared; `APP_TRUSTED_PROXIES`
   naming the balancer so the per-address throttle sees client addresses;
   size the public tier by requests per second of this mix on the target
   hosts (php-fpm or Octane), not from this laptop.
4. **Review the legacy rows** the data-quality command lists after the
   first production run (`NEEDS_DATA_REVIEW`), and run
   `branding:migrate-addis-one` once (see `docs/branding.md`).

## 4. Final run (2026-10-12, after the rebrand)

| Check | Result |
| --- | --- |
| `php artisan migrate --pretend` | nothing to migrate (development database at head; every migration applied there and probed, including the `ON DELETE RESTRICT` of `employee_id`) |
| `php artisan route:list` | 1,321 routes, no error |
| `vendor/bin/pest tests/Unit` | 155 passed |
| `vendor/bin/pest tests/Feature` | run in four processes because a single process exhausts the 512 MB limit set in `phpunit.xml.dist` inside a pre-existing zip-export test: 1,731 + 63 + 228 + 1,161 passed, 1 skipped; 4 failures — the three caused by the uncommitted sidebar and ID-card work (see note) and one source-shape expectation in `PublicHomeContentTest` that the branding pass had changed (fixed in the following commit, 20/20 green). `tests/Feature/Performance/PerformanceWorkflowTest.php` hangs when run as a file (CPU-bound, no output); each of its 29 tests passes on its own — state leaks between its tests, outside this module |
| Feedback suites (`tests/Feature/PublicFeedback`, 21 files) | 217 passed, 1 skipped (the PostgreSQL-only hard-delete case on SQLite) |
| Branding suites (`tests/Feature/Branding`, 5 files) | 19 passed |
| `vendor/bin/pint --test` | every file touched by this work passes; the repository-wide run lists 110 pre-existing files outside this work (cafeteria, assessment, discipline, migrations) |
| `composer audit --locked` | no advisories |
| `npm audit --omit=dev` | 0 vulnerabilities |
| `tsc --noEmit` | clean |
| `vite build` | clean |
| `php artisan production:readiness` (development database) | Branding and Public feedback sections PASS except WARN: favicon not uploaded (BRAND_ASSET_REQUIRED), routing rules present, no failed exports, no rows awaiting review |
| Browser pass (Amharic and English, 390×844 / 820×1180 / 1280×900) | `docs/public-feedback-uat.md` §10 |
| Load tests | `docs/public-feedback-load-testing.md` |

The three feature failures in every full run are in the working tree's
uncommitted sidebar and ID-card work (`CourtCasesPlaceholderTest`,
`IdCardFrontFieldsTest` ×2); none of the commits above touches those
files, and the committed tree keeps `courtCases`. `DemoDataSeederTest`
01/10 are known clock-dependent failures documented before this work.

## 5. Not measured, not claimed

- PostgreSQL CPU and Redis latency (not on the rig).
- Behaviour with the cache store down: the throttle middleware throws, so
  the public form fails closed (framework behaviour, not exercised).
- A submission table of millions of rows: the rig held 22k.
- Malware scanning: no scanner is contracted; files are never served inline.
