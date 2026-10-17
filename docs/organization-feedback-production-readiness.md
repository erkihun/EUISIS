# Organization feedback form: production readiness

Validation, analytics integration and production gate for the organization
QR feedback form, run on 17 October 2026 against the existing
implementation. Nothing was rebuilt.

**Verdict: CONDITIONAL_ORGANIZATION_FEEDBACK_PRODUCTION_READY.** There is no
open P0 or P1. Before go-live, the policy decisions in §5 must be made, at
minimum approving the questionnaire wording before version 1 is published.

## 1. Defects found and fixed

| # | Severity | Defect | Fix | Proof |
|---|---|---|---|---|
| 1 | P1 (major localization failure) | On a first visit (a scanned QR, no language cookie yet), the page showed Amharic while the questions, categories, institution name and validation messages came from the server in its default language (English). | The server shares `textLocale` (the language it actually wrote in). `LocaleContext` reloads the page once when that differs from the language on screen. | Browser: first-visit Amharic Complaint shows "የመጡበትን አገልግሎት አግኝተዋል?"; exactly one extra request |
| 2 | P1 (structured answers) | Structured answers did not reach the Situation Room: `PublicFeedbackAnswerStatistics` was called only by a test. | Two panels per Public Voice category, built on the scoped base ([organization-feedback-analytics.md](organization-feedback-analytics.md)). | `SituationRoomQuestionAnalyticsTest` (10 tests: roll-up, sibling isolation, privacy, versions, reconciliation, hierarchy change) |
| 3 | P2 | A page opened before a questionnaire was published sent no version. A required question the visitor never saw then failed with an error the page could not show. | The version is required when the type has a required question, and answers with no version are refused ("the form was updated"). With only optional questions the send is accepted, and no version is recorded. | 2 tests |
| 4 | P2 | Validation, controller and service each read the published questionnaire separately, so a publish mid-request could validate one version and store another. The per-request memo was keyed by `spl_object_id`, which can be reused. | `scoped` binding and a `WeakMap` keyed by the request. | "loads the published questionnaire once per request" (1 query) |
| 5 | P2 (privacy) | The logo URL (`/storage/organizations/logos/{organization id}/…`) put the internal organization id in the public page. | `GET /feedback/{token}/logo`, 404 for any unresolved token. | Test; browser: no organization id in the HTML |
| 6 | P2 (receipt accuracy) | The Complaint receipt said "sent to the service-quality team", also for an unrouted complaint. | "Your complaint has been recorded and will be reviewed." (EN/AM) | Browser |
| 7 | P2 (analytics readability) | The 1–5 rating segments used the categorical palette, so 1 and 5 were both dark blue. | An opt-in `diverging` palette (orange → slate → blue, safe for red–green colour blindness). | Browser legend colours; test asserts `palette` |
| 8 | P3 | Outdated text: the "by service" panel and the SERVICE_LINKAGE_REQUIRED notice said only employee QR codes can name a service. | Text updated (EN/AM). | — |
| 9 | P3 (language) | "ጎብኚ" (tourist/visitor) for the citizen on staff screens; "አማራጭ" for a choice option in admin messages. | "ተገልጋይ"; "ምርጫ". | i18n audit |

## 2. Gate

| Gate | Result |
|---|---|
| P0: wrong target, cross-organization leakage, PII exposure, attachment exposure, stored XSS, target tampering, data corruption, destructive migration | **None open.** Tampering, IDOR, XSS, CSRF, evidence and enumeration are covered in [organization-feedback-form-security.md](organization-feedback-form-security.md). The migration is additive, and its `down()` refuses to drop answered data. |
| P1: Feedback / Tip-off / Complaint cannot submit; answers or ratings wrong; mobile unusable; Situation Room totals wrong; tracking broken; major localization failure | **None open** (#1 and #2 fixed). Tracking is not a regression: public tracking was never built (NEEDS_POLICY_DECISION). |

## 3. Commands

| Command | Result |
|---|---|
| `php artisan about` | Laravel 12.58.0, PHP 8.4.23, pgsql, database cache/queue/session |
| `php artisan route:list --name=public-feedback.organization` | 6 routes, including the new `…logo` |
| `php artisan migrate:status` / `migrate --pretend` | all ran; nothing to migrate |
| `php artisan test` (full, parallel, `APP_LOCALE=en`) | see §3.1 |
| Feature suites touched | PublicFeedback 309 passed, 1 skipped; ServiceFeedback + Unit 305 passed; Situation Room 127 passed, 1 failed (`SituationRoomMetricsTest`, attention-rule threshold, failing on HEAD before this work). After the logo change, the organization-form, flow, QR and Situation Room question files: 103 passed |
| `vendor/bin/pint --test` | the 44 PHP files changed in this feature pass; the repository-wide run fails on unrelated legacy files |
| `composer audit --locked` | no advisories |
| `npm audit` | 10 advisories in build/dev tooling only (see the security document) |
| `npm run typecheck` | pass |
| `npm run i18n:audit` (strict) | pass (17,642 keys; warnings only) |
| `npm run build` | pass (the existing chunk-size warning only) |
| Browser E2E, accessibility | see [organization-feedback-form-uat.md](organization-feedback-form-uat.md) §3: all flows pass, axe 0 violations |

No lint, static-analysis or JavaScript unit-test runner is configured beyond
the above (no ESLint script, PHPStan config or Vitest in `package.json` /
`composer.json`).

### 3.1 Full suite

3,867 passed, 1 skipped, 3 failed (30,250 assertions, 71 minutes, 6 processes).
The 3 failures are the known environment and HEAD failures, unrelated to this
feature, and they fail the same way without these changes:

- `BrandingMigrationTest`: expects "Addis One" and gets the stored brand name.
- `SharedStorageTest`: the S3 adapter is not installed locally.
- `SituationRoomMetricsTest`: the attention-rule threshold test.

## 4. Performance (local PostgreSQL, small data)

| Request | Queries | DB time | Payload | Slowest query |
|---|---|---|---|---|
| Organization Feedback form (Inertia) | 56 | 108 ms | 11.8 KB | services count, 9.6 ms |
| Organization Complaint form (full HTML, Amharic) | 50 | 75 ms | 19.9 KB | QR token lookup, 6.6 ms |
| Situation Room Feedback, deferred panels (cold / cached) | 64 / 31 | 218 / 124 ms | 8.1 KB | permission loading, 35–42 ms |
| New answer aggregates (each) | 1 | 0.8–4.4 ms | — | — |

Most form-page queries are the application-wide shared props, not the form.
Question options load eagerly with the version (`questions.options`), as do
the labels for the codes on screen, so there is no N+1. Service catalogs over
150 entries are searched rather than sent with the page. **No index was
changed.** The answer join and grouping are covered by `pfa_submission_idx`,
`pfa_code_rating_idx` and `pfa_option_idx`. Plans at production volume need
real data (NEEDS_DATA).

## 5. Open items

### NEEDS_POLICY_DECISION

1. Approve, change or reject the proposed questionnaire items before
   publishing version 1. The local database now has version 1 published from
   the template, for testing only.
2. Demographic questions (none collected).
3. A separate "improvement suggestion" type beside Tip-off.
4. Which organization units are public service points (currently: type
   `office`, or a unit that replaced an institution office).
5. Public tracking of a reference, and a reference for Feedback.
6. Per-institution questionnaires (one city-wide questionnaire today).
7. A per-question summary statistic for the Situation Room (mean, or the share
   answering 4–5) and its trend.
8. An "as at submission" hierarchy view for historical roll-up (today the
   published structure is used; rows never move).

### NEEDS_LANGUAGE_REVIEW

The proposed wording listed in
[organization-public-feedback-form.md](organization-public-feedback-form.md)
§10, plus:

| Key | Amharic |
|---|---|
| `stepOf` | ደረጃ {current} ከ{total}: "ከ{total} ደረጃዎች {current}ኛው" may read more naturally |
| `complaintSubmittedDetail` | ቅሬታዎ ተመዝግቧል፤ ይመረመራል። መለያ ቁጥሩን ይያዙ። |
| Situation Room `*_question_ratings` / `*_question_choices` titles and descriptions | የአገልግሎት ተሞክሮ ደረጃዎች በጥያቄ / ለምርጫ ጥያቄዎች የተሰጡ መልሶች |
| `columns.share_of_answers` | ከመለሱት ድርሻ |
| `serviceUnstructured` | በተገልጋዩ የተገለጸ፤ ከአገልግሎት ዝርዝሩ ጋር ያልተገናኘ |

### NEEDS_DATA

- No institution in the local database has a published service catalog or
  more than one office. The catalog, search and office paths are proven by
  automated tests only.
- No routing rules exist locally, so the browser run saw only the "unrouted"
  path. Routed paths are covered by `PublicFeedbackRoutingTest`.
- Production-volume query plans for the answer aggregates.

### SERVICE_LINKAGE_REQUIRED

Institutions without a published service catalog receive free-text services,
which are kept on the submission but are not counted in "by service"
analytics.

### Other

- MALWARE_SCANNER_REQUIRED for evidence.
- Not tested: real devices and screen readers (axe and keyboard checks
  only).

## 6. Local state left by the browser run

In the local PostgreSQL database: questionnaire version 1 (published from the
template); feedback QR codes for Trade Bureau and Lemi Kura (active); three
test submissions about Trade Bureau (`E2E-FB-EN`, `PSC-2026-000001`,
`PTO-2026-000001`) with one PNG in `storage/app/private/service-feedback/`.
None of it is in a migration or seeder.
