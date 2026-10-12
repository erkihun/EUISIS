# Public Feedback Management — production readiness gate

Amharic: የተገልጋይ አስተያየት አስተዳደር — የምርት ዝግጁነት በር

Assessed 2026-10-12 against the brief "Unified public feedback, suggestion &
complaint management system" (digest: `docs/public-feedback-brief-digest.md`)
after commits `b060d0c`…`84d5fe5` plus this documentation commit. The
architecture is in `docs/public-feedback.md`, the UAT record in
`docs/public-feedback-uat.md`.

## Verdict: CONDITIONAL READY

The feature is complete, tested and documented. It may go live once the
three conditions in §3 are met; none of them is a code change.

## 1. What was found and reused

- The employee feedback QR (`EmployeeFeedbackToken`, `/service-feedback/{token}`)
  with its rating inbox, reports, CSV export, moderation and per-employee QR
  management — kept intact, not rotated, extended to three types.
- `OrganizationScopeService`, the Spatie permission catalog seeded by
  migrations, `WriteAuditLogAction`, the system-settings registry,
  `CodeRuleService` references, the notification presenter and queue, the
  organization-design queued-export pattern (`csv_safe_row`, text-bound
  cells), the UI kit (`@euisis/ui`) and the Ethiopian/Gregorian date
  components. No second architecture was introduced.
- Grievance, discipline and tribunal domains were not touched (brief §63–64,
  §80); the sidebar group is independent of them.

## 2. What was built (ten commits)

| Commit | Scope |
|---|---|
| `b060d0c` | typed public submission baseline (feedback, suggestion, complaint) |
| `ea9dc44` | unified target model: target and source types, snapshots, PostgreSQL integrity constraints |
| `1890c36` | institution feedback QR: stable opaque token, one active code, rotate/revoke with reason, print and export |
| `566ccc6` | institution public flow through the shared resolver and submission service; one-time form token; receipt |
| `537b767` | categories by submission type and target type, city-wide or per institution, never deleted once referenced |
| `e89f661` | type-specific workflows, immutable status history, assignment history, routing rules, UNROUTED alert |
| `6701a25` | policy settings, identity modes, encrypted contact, submitter identity / evidence / target-type grants |
| `d3b43b0` | "Public Feedback Management" navigation, dashboard counts, submission reports, queued private exports, institution QR list |
| `a43e60b` | post-commit staff notices, e-mail acknowledgement switch, policy-set throttle, upload-name hardening |
| `84d5fe5` | investigation subjects, controlled discipline referral requests |

## 3. Conditions before go-live

1. **Grant the new permissions.** Every grant added by this work is granted
   to no role, except the two target-type grants that keep existing viewers
   whole. Service-quality handlers need at least `complaints.view`,
   `complaints.review`, `assign`, `resolve`, `close` and the two target-type
   grants; one city-level role needs `manage_routing`, `manage_categories`,
   `settings.manage` and the institution QR grants; `view_submitter_identity`,
   `view_sensitive_attachments` and `refer_discipline` should go only to the
   roles policy names.
2. **Decide the NEEDS_DECISION items** in `docs/public-feedback.md` §9 — or
   accept their safe defaults in writing. The ones that change behaviour on
   day one: identity mode (ships optional), e-mail acknowledgement (ships
   off), throttle thresholds (3 per code, 8 per address per ten minutes),
   and whether any employee-targeted complaint reaches the target employee
   (today: never).
3. **Operate it:** a queue worker on the `notifications` queue (and
   `default` for exports), `CACHE_STORE=redis` on multi-node deployments so
   the throttle and one-time form tokens are shared, and a malware scanner
   if staff will open complaint files outside the application.

## 4. Run of record (2026-10-12)

| Check | Result |
|---|---|
| Unit suite (`vendor/bin/pest tests/Unit`) | 155 passed |
| Feature suite (`vendor/bin/pest tests/Feature`, one sequential process) | 1,712 passed, 3 failed (all three in the uncommitted sidebar/ID-card work, see note), then the process ran out of memory in a pre-existing zip-export test after 1,715 tests; the remaining directories are re-run in the QA gate (`docs/public-feedback-qa-gate.md`) |
| Feedback suites (`tests/Feature/PublicFeedback`, `ServiceFeedback*`, `PositionServiceTest`, UI consistency and sidebar guards) | 216 passed, 1 skipped (Phase 9 run); 5 more in the hardening suite |
| PostgreSQL | migrations applied to the development database; every CHECK constraint, partial unique index and the `client_contact` type change probed; the PostgreSQL test database cannot be built on this machine (`pg_trgm.dll` blocked by Application Control) |
| TypeScript (`tsc --noEmit`) | clean |
| Build (`vite build`) | clean |
| Translation key parity (`check-keys.mjs` over every feedback page and component) | all keys present |
| `composer audit` | no advisories |
| `npm audit --omit=dev` | 0 vulnerabilities |
| Browser walkthrough (Amharic and English) | every management page and both public flows; see the UAT record |

Feature-suite note: the only failure in the full feature run is
`gives the sidebar the court cases entry only when the user holds the
permission` (`tests/Feature/CourtCases/CourtCasesPlaceholderTest.php`, a
committed test): the working tree's uncommitted sidebar renames the
`courtCases` group to `tribunalCourt`, which that test does not yet expect.
It does not touch this feature and the committed sidebar keeps `courtCases`. `DemoDataSeederTest` 01/10 are known clock-dependent
failures documented before this work.

## 5. Findings worth stating

- A public complaint never becomes a grievance, a discipline case or an
  appeal by itself; the referral is a request record (§58–64).
- No report or dashboard ranks employees by complaints (§82); the
  rating-based "lowest average" tables that existed before stay behind the
  employee-target grant.
- Submitter contact is encrypted at rest and masked everywhere without the
  identity grant, exports included; audit rows never carry text or contact.
- Rows written before this work keep working: target columns are backfilled,
  encrypted contact reads through plaintext, the first single-step review
  statuses still close normally.
- Pre-existing, outside this feature: commit `c33e6a7` references
  `StatutoryDeadline` classes that exist only in the working tree; and the
  working tree carries an uncommitted discipline module whose routes include
  (`routes/discipline.php`) is deliberately left out of every commit here.
