# Admin: Public Feedback production readiness

Audit, fixes and gate for the admin side of Public Feedback Management, with
organization-targeted Feedback, Tip-offs and Complaints, 17 October 2026.
The existing domain was audited first. Nothing was duplicated: the new list
reuses the scoped query that the dashboard, reports and export already use.

**Verdict: CONDITIONAL_ADMIN_ORGANIZATIONAL_FEEDBACK_READY.** There is no open
P0 or P1. The conditions are the policy decisions in §5. Before city-wide
volume, also verify the city-wide default sort and count at scale (§4,
NEEDS_DATA).

## 1. Found and fixed

| # | Severity | Defect | Fix |
|---|---|---|---|
| 1 | **P1 (permission leak)** | `PublicFeedbackMetrics::query()` ignored the Complaint grant: a holder of `service_feedback.view` alone received Complaints in the dashboard overview, the submission reports and their **export, including the narrative** | `PublicFeedbackVisibility::visibleTypes()` / `applyTypeScope()` applied in the query; filter options limited to visible types and targets (tested) |
| 2 | **P1 (workflow)** | The Feedback review wrote `status` directly: "reviewed" or "resolved" from any status (including closed or hidden), no history, no `resolved_at` (missing from days-to-outcome), no way to close | Through `PublicFeedbackWorkflow`: valid moves only (422 otherwise), timestamps, history with the note, audit; a Close step behind `service_feedback.close`; the page offers only the allowed moves and shows the history (tested) |
| 3 | **P1 (admin list)** | No list across types; organization-targeted Feedback shown as "—" in an Employee column; no target-type filter; no Unrouted entry point | New **All Submissions** and **Unrouted** pages (scoped, filtered, searched, sorted, paginated, cards that reconcile); sidebar links; the Feedback list gets a target column and filter, the catalog service and a category filter (tested) |
| 4 | P2 (history) | Restoring a hidden Feedback always reset it to "pending", without history | Restores the previous status; both steps recorded (tested) |
| 5 | P2 (identity) | The Feedback list showed "Anonymous client" when the viewer only lacked the identity grant | "Hidden — needs the submitter identity permission"; "Anonymous" only when nothing was given |
| 6 | P2 (export language) | The queued export and the Feedback CSV were English only: raw status codes, English names, Gregorian dates for Amharic users | The requester's language is stored (`public_feedback_report_exports.locale`, additive migration): Amharic labels, names and Ethiopian dates, or English and Gregorian (tested) |
| 7 | P2 (calendar) | The admin date filters were native Gregorian pickers in Amharic | `LocalizedDatePicker` (Ethiopian in Amharic); date chips formatted in the user's calendar |
| 8 | P2 (language) | Admin screens used the public bilingual type labels ("Complaint · ቅሬታ") in both languages; English nav said "Suggestions" for Tip-offs; a hard-coded English "required" on every form label and "4 out of 5" on ratings in Amharic | Monolingual admin labels; "Tip-offs"; the shared form label and the rating text localized |
| 9 | P2 (target display) | Detail pages showed an institution target by its English snapshot only, with the heading "Submissions about this institution" | Localized institution name and code; "About the institution"; logo, service point and category on the Feedback page |
| 10 | P3 | Feedback status-tab counts and the reports/CSV ignored the user's identity grant in search, so tab counts could differ from the list | The user is passed to every filter call |
| 11 | P3 (a11y) | Low-contrast reference link (new page); invalid `<dl>` on the Complaint detail | Fixed |

## 2. Gate

| Requirement | Result | Evidence |
|---|---|---|
| Organization Feedback / Tip-off / Complaint visible to the correct scope | yes | scope dataset test (woreda, sub-city, bureau, sibling, other branch) |
| Employee-targeted records still work, behind their own grant | yes | employee-target test; existing PublicFeedback suites |
| Hierarchy roll-up; sibling leakage blocked | yes | the same tests; IDOR 403 on detail, transition, assign, review, hide, download |
| Detail page; structured answers; historical version wording | yes | `OrganizationFeedbackFormTest`, `PublicFeedbackQuestionnaireAdminTest` (version wording), browser |
| Attachments private and authorized | yes | sibling 403, foreign or unknown file 404, `/storage` path 403 |
| Routing, unrouted queue, assignment; target immutable | yes | unrouted queue test; workflow tests check `organization_target_id` |
| Status workflow and history | yes | Feedback, Complaint and Tip-off workflow tests; invalid moves 422 |
| Filters, search, server pagination, sorting | yes | filter, forged-filter and search tests; 25 per page |
| Exports scoped, with identity only with the grant, in AM/EN | yes | export tests (existing and new) |
| Cards reconcile with the list and the database | yes | reconciliation test |
| Situation Room reconciles; no PII in aggregates | yes | `SituationRoomQuestionAnalyticsTest`, `SituationRoomScopeTest` |
| No XSS | yes | text rendered as React text; literal `<script>`/`<b>` in the browser |
| Build and tests | see §3 | |

**Remaining P0: none. Remaining P1: none.**

## 3. Commands

| Command | Result |
|---|---|
| `php artisan migrate:status` / `migrate --pretend` | one new additive migration (`2026_10_17_100000_add_locale_to_public_feedback_report_exports`); applied locally |
| `php artisan route:list` | `service-submissions.index`, `service-submissions.unrouted` added |
| `php artisan test` (full, parallel, `APP_LOCALE=en`) | 3,891 passed, 1 skipped, 3 failed (30,654 assertions, 51 min). The 3 failures are the known environment and HEAD failures (`BrandingMigrationTest`, `SharedStorageTest`, `SituationRoomMetricsTest`), unrelated to this work |
| `vendor/bin/pint --test` (changed files) | pass |
| `composer audit --locked` | no advisories |
| `npm audit` | 10 advisories in build tooling only (unchanged; see organization-feedback-form-security.md) |
| `npm run typecheck`, `npm run i18n:audit` (strict), `npm run build` | pass |
| Browser E2E and axe | [admin-public-feedback-uat.md](admin-public-feedback-uat.md) §3 |

No ESLint, PHPStan or JavaScript test runner is configured in this repository.

## 4. Performance and indexes

Measured on local PostgreSQL (four submissions, super administrator):

| Page | Queries | DB time | Payload |
|---|---|---|---|
| All Submissions | 179 | 372 ms | 40.5 KB |
| Unrouted | 177 | 378 ms | 39.0 KB |
| Feedback inbox (existing) | 180 | 411 ms | 39.9 KB |
| Complaint detail (existing) | 189 | 447 ms | 40.8 KB |

154 of the 179 queries are single-key reads of the **database cache store**
(`CACHE_STORE=database`) for application-wide settings and permissions,
the same on every admin page. The list itself runs about 25 queries: the
page, its count, the cards (three grouped counts), one eager load per
relation, and the filter options. There is no N+1: handler names come from
snapshots, and labels are eager-loaded. **Use Redis (or another in-memory
cache) in production.**

Indexes: **none added.** The scoped list, filters and cards are covered by
`esf_org_type_submitted_idx` (organization_id, type, submitted_at),
`esf_context_org_status_idx` (organization_id, status, submitted_at),
`esf_target_type_submitted_idx`, `employee_service_feedback_category_id_index`
and the unique `reference_number`.

NEEDS_DATA: a **city-wide** viewer's default list (no organization filter,
newest first) has no index on `submitted_at` alone, and the paginator's total
`count(*)` scans the slice. Seed volume with
`app/Console/Commands/Scale/SeedPublicFeedbackScale.php`, read the plan, and
add `(submitted_at DESC, id DESC)` and/or switch city-wide views to cursor
pagination only if the plan shows the sort or the count dominating.

## 5. Open items

### NEEDS_POLICY_DECISION

1. Free-standing internal notes, without a status change (today the transition
   or assignment reason, and the Feedback review note, serve as notes).
2. Reopening a closed submission (not in the approved workflow).
3. A required resolution summary per type.
4. A resolution notice to the submitter (only the acknowledgement is sent).
5. Creating a Management Action (Situation Room Action Register) from a
   submission page, with a structured source link.
6. Bulk status changes (deliberately none; only scoped export).
7. Public tracking of a reference.
8. SLA targets for response and resolution (SLA_CONFIGURATION_REQUIRED;
   no overdue metric is shown).

### NEEDS_DATA

- The city-wide sort and count at volume (§4).
- No institution in the local data has a service catalog or several offices;
  those paths are covered by automated tests.

### Known, not from this phase

- The amber "Pending" badge text measures 4.44:1 (needs 4.5:1). It is a
  shared theme token used across the application.
- `npm audit` advisories in build tooling.

## 6. Local state changed by the run

The new migration was applied to the local database. One Feedback (Trade
Bureau) was marked **reviewed** and the Complaint `PSC-2026-000001` was
**assigned** to Super Admin while testing in the browser.
