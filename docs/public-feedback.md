# Public Feedback Management — architecture and operations

Amharic: የተገልጋይ አስተያየት አስተዳደር — መዋቅር እና አሠራር

One system takes what the public says about a service — feedback (አስተያየት,
a rating), a suggestion (ጥቆማ) or a complaint (ቅሬታ) — through two QR codes:
the employee feedback QR already printed on the ID card, and a new
institution feedback QR. Both resolve on the server to exactly one target;
the browser never chooses it. Everything then flows through one table, one
workflow engine, one set of permissions and one management area.

The institution QR opens the digitized organization feedback form: service
information, a configurable and versioned questionnaire, and structured
answers. See [organization-public-feedback-form.md](organization-public-feedback-form.md).

A public complaint is an allegation about a service. It is not an employee
grievance, not a disciplinary case and not an administrative appeal; nothing
here creates any of those (brief §58, §63–64, §122). Transport is out of scope.

Implemented 2026-10-12 in commits `b060d0c`…`84d5fe5` on `main`; the plan is
`docs/superpowers/plans/2026-10-12-public-feedback-unified.md`, the brief
digest `docs/public-feedback-brief-digest.md`, the UAT record
`docs/public-feedback-uat.md`, the readiness gate `docs/public-feedback-readiness.md`.

## 1. Targets and sources

| Target | Source | Public URL | Resolver |
|---|---|---|---|
| `employee` | `employee_card_qr` — `EmployeeFeedbackToken` (64-hex, stable, never rotated by this work, no PII) | `/service-feedback/{token}` | `PublicFeedbackTargetResolver::resolveEmployeeToken` |
| `organization` | `organization_feedback_qr` — `OrganizationFeedbackQrCode` (64-hex, one active per institution) | `/feedback/{token}` | `PublicFeedbackTargetResolver::resolveOrganizationToken` |

Both resolvers return a `TargetContext` DTO (target type, source type, the
employee or the organization, the current assignment and its office context,
bilingual name snapshots, and `publicDisplay()` which exposes only the office,
unit and masked role, or the institution's name and logo). Unknown, revoked,
disabled or suspended tokens and inactive institutions all give one generic
unavailable page; nothing is stored.

The employee flow keeps every existing rule: the card QR is not rotated, the
ID-checker OTP flow is untouched, an inactive card or token offers no form.
The institution QR is minted only by a deliberate action (never by opening
the page), one active code per institution is enforced by a partial unique
index, a disabled code resolves nothing until re-enabled, rotation and
revocation take a stronger permission and a reason, a revoked token is never
reused, and every state change and export is audited.

## 2. Data model

All three types live in `employee_service_feedback` (the name predates
organization targets). Columns added by this work: `target_type`,
`source_type`, `organization_target_id`, `source_organization_qr_id`,
`target_name_snapshot`, `target_code_snapshot`, `category_id`, `subject`,
`submitter_identity_mode`, `submitted_at`, `acknowledged_at`, `assigned_at`,
`resolved_at`, `closed_at`; `employee_id` became nullable; `client_contact`
became `text` and is encrypted at rest (read-through for rows written before).

PostgreSQL CHECK constraints (mirrored in PHP by the model's `saving` guard):

```
esf_target_integrity_chk  (employee target ⇒ employee_id set, organization_target_id null)
                          (organization target ⇒ organization_target_id set, employee_id null)
esf_source_target_chk     employee ⇔ employee_card_qr, organization ⇔ organization_feedback_qr
```

Rows written before this work carry `target_type = employee`,
`source_type = employee_card_qr` and `submitted_at = created_at` by backfill;
history is never rewritten. A submission keeps its original target forever:
subjects and referrals are separate relations (§62).

New tables (all additive, UUID keys, PostgreSQL-safe, no `migrate:fresh`):

| Table | Purpose |
|---|---|
| `organization_feedback_qr_codes` | one active code per institution, rotation chain, scans, revocation reason |
| `public_feedback_categories` | city-wide or institution-scoped categories by submission type and target type; retired, never deleted once referenced |
| `public_feedback_routing_rules` | organization × target type × submission type × category → handler, priority, effective window |
| `public_feedback_assignments` | every hand-over, with handler name snapshot and `ended_at`; one open row per submission |
| `public_feedback_status_history` | append-only transitions (model refuses update/delete) |
| `public_feedback_report_exports` | queued, private export requests |
| `public_feedback_subject_employees` | employees an internal review named as involved |
| `public_feedback_referrals` | controlled discipline referral requests |

Migrations: `2026_10_12_000100` … `2026_10_12_000700`. Each registers its
permissions through `Permission::firstOrCreate`; none grants them to a role
except the two target-type grants, which `000500` gives to every role that
already viewed submissions so nobody lost what they saw.

## 3. Submission pipeline

`PublicFeedbackSubmissionService::submit(TargetContext, type, data, request)`:

1. duplicate guard (same source, type, text, rating and contact within two
   minutes returns the existing row); the typed forms also carry a one-time
   `form_token` that is consumed atomically, so a replayed form is refused;
2. in one transaction: the row with target/source columns, context ids,
   snapshots, category, identity mode in force, client fields (nothing in
   anonymous mode), a `PSC-{YEAR}-{SEQ}` reference for complaints through
   `CodeRuleService`, private attachments, the initial history row, routing,
   and the submission audit;
3. after commit: the unrouted alert or the handler notice, and the
   acknowledgement e-mail when the policy allows.

Rate limiting (`service-feedback-submit`): per QR code + address and per
address over ten minutes, on the configured cache limiter store (Redis when
`CACHE_STORE=redis`), thresholds from the policy (defaults 3 and 8).

Uploads (complaints only, when the policy allows): at most three files of
5 MB, PDF/JPEG/PNG by extension **and** sniffed MIME type, a file name with
no path parts, control characters or executable/script extension anywhere
(`evidence.php.pdf` is refused), stored under a generated name on the
private `local` disk with a SHA-256, served only through the authorised
download route with no-store/nosniff headers. No antivirus is installed;
`scan_status` stays `not_scanned`.

## 4. Workflow

`PublicFeedbackWorkflow` holds the transitions per type over one status set
(`ServiceFeedbackStatus`):

```
feedback    pending → reviewed | resolved → closed
suggestion  pending → assigned | unrouted → under_review → accepted | not_adopted → closed
complaint   pending → assigned | unrouted → under_review → resolved → closed
```

`assigned`, `unrouted`, `pending` and `hidden` are set by routing, assignment
and moderation, never chosen by hand. Every transition stamps the matching
timestamp, appends a history row and an audit entry, and — for outcomes —
notifies whoever assigned the submission. Transition permissions: the type's
review grant for `reviewed`/`under_review`, `service_feedback.resolve` for
outcomes, `service_feedback.close` for closure.

Routing (`PublicFeedbackRoutingService`): the most specific rule in effect
wins — institution before city-wide, then target type, submission type,
category — then the lowest priority number, then the older rule. No match →
`unrouted` and the holders of `service_feedback.manage_routing` in scope are
notified; a submission never falls to its target employee. Rating feedback
is not routed (§48). Scoped administrators manage rules for their own
institutions; city-wide rules need a city-level account and may not name one
institution's unit or a user confined to one institution.

Assignment (`PublicFeedbackAssignmentService`): handler = named user,
organization unit or role; a unit must be the institution's own, a user must
be able to work in it, and never the employee the submission is about. A
reassignment ends the open row and opens a new one.

## 5. Visibility and privacy

| Grant | Effect |
|---|---|
| `service_feedback.view` / `complaints.view` | see submissions (ratings and suggestions / complaints) within organization scope |
| `service_feedback.view_employee_target` / `view_organization_target` | which target types are visible at all |
| `service_feedback.view_submitter_identity` | the submitter's name and contact; otherwise masked everywhere, CSV included |
| `service_feedback.view_sensitive_attachments` | complaint files listed and downloadable; otherwise only a count |
| `service_feedback.review` / `complaints.review` | acknowledge, review, name investigation subjects |
| `service_feedback.assign` / `reassign` / `resolve` / `close` | workflow actions |
| `service_feedback.refer_discipline` | request or decide a discipline referral |
| `service_feedback.manage_categories` / `manage_routing` | category and routing administration (city-wide needs a city-level account) |
| `service_feedback.organization_qr.view` / `create` / `export` / `disable` / `rotate` | institution QR lifecycle |
| `service_feedback.settings.manage` | the public feedback policy (city-level account) and employee QR management |
| `service_feedback.export` | request submission exports; download your own or those in scope |

Every management query applies permission + `OrganizationScopeService` +
target-type grant; the employee a submission is about never sees, moves,
assigns or refers it, whatever role they hold. Submission IP and user agent
never reach a browser; audit rows never carry the narrative or the contact.

## 6. Policy settings (`service_feedback` group, `/feedback-admin/settings`)

| Key | Default | Meaning |
|---|---|---|
| `enabled` | true | master switch; off → every public page shows the unavailable notice |
| `feedback_enabled`, `suggestion_enabled`, `complaint_enabled` | true | a disabled type leaves the landing and answers 404 |
| `identity_mode` | optional | anonymous (nothing collected), identified (name + valid phone/e-mail required), optional |
| `allow_attachments` | true | complaint files |
| `acknowledgement_enabled` | false | e-mail receipt to a submitter who left an e-mail (type, reference, time; never the text) |
| `rate_limit_per_source`, `rate_limit_per_ip` | 3, 8 | submissions per QR code / per address in ten minutes |

The group is edited only here (it is excluded from the general System
Settings page) and every change is audited with before/after values.

## 7. Management area (sidebar "Public Feedback Management")

Dashboard (`/feedback-admin/dashboard`, scoped counts by type, target and
status across all three types plus the rating analytics) · Client feedback
(`/feedback-admin`) · Suggestions (`/service-suggestions`) · Complaints
(`/service-complaints`, status tabs per workflow, "my queue") · submission
detail (`/service-submissions/{type}/{id}`: history, handler, transition
form, subjects, referral) · Categories (`/feedback-admin/categories`) ·
Routing rules (`/feedback-admin/routing-rules`) · Organization QR codes
(`/feedback-admin/organization-qr`, per-institution page
`/organizations/{id}/feedback-qr`) · Feedback reports
(`/feedback-admin/reports`) · Submission reports
(`/feedback-admin/submission-reports`, breakdowns and queued exports) ·
Settings (`/feedback-admin/settings`).

Reports never rank employees by complaints (§82). Exports run on the queue as
the requester, land on the private disk, bind strings as text and pass CSV
rows through `csv_safe_row()`, and are audited.

## 8. Notifications

`PublicFeedbackNotification` (database, plus mail when the platform's e-mail
notifications are on) for `unrouted`, `assigned`, `resolved` and `referred`;
it carries type, reference and a portal link only. The acknowledgement
(`PublicFeedbackAcknowledgement`, mail, on demand to the submitter's address)
carries type, reference and time, no link, in the submitter's language. All
are queued after commit; a failing provider is logged and never fails the
submission. Worker: `php artisan queue:work --queue=notifications,default`.

## 9. NEEDS_DECISION registry

Nothing below is built; each has a documented safe default.

| # | Decision | Current behaviour |
|---|---|---|
| 1 | SLA / response deadlines per type, category, institution (§91–92) | none; `assigned_at`, `acknowledged_at`, `resolved_at`, `closed_at` are recorded so a future SLA can be snapshotted |
| 2 | Retention, deletion, withdrawal by a submitter (§113–114) | nothing deleted automatically; complaints cannot be deleted by ordinary actions |
| 3 | Per-institution policy overrides (§94) | one city-wide policy |
| 4 | Suggestion outcome vocabulary (§49) | accepted for action / not adopted |
| 5 | Public tracking of a submission (§41) | none; the receipt and the acknowledgement say so |
| 6 | CAPTCHA (§37) | no provider in the project; throttle, one-time form token and duplicate guard apply |
| 7 | SMS acknowledgement (§89) | e-mail only, and only when switched on |
| 8 | Target-employee self-view of feedback/suggestions/complaints (§55) | no self-service view |
| 9 | Malware scanning provider (§65) | `not_scanned`; files never served inline |
| 10 | Throttle thresholds (§38) | configurable, defaults 3 per code and 8 per address per ten minutes |
| 11 | Which roles receive which grants | every new grant is granted to no role except the two target-type grants (data-preserving) |
| 12 | Discipline case creation from an accepted referral (§59–60) | the discipline module fills `disciplinary_case_id`; nothing here creates a case |
| 13 | Follow-up action items (§90) | not modelled |
| 14 | Feedback/suggestion reference numbers (§40) | complaints only (`PSC-`) |

## 10. Operations, migration safety and rollback

Deploy: run the migrations (additive; the `000500` column widening of
`client_contact` to `text` is the only ALTER on an existing column), build
the assets, grant the new permissions to the chosen roles (`DefaultRoleMatrix`
gives Organizational Admin the two target-type grants), keep a queue worker on
the `notifications` queue, and configure `CACHE_STORE=redis` on multi-node
deployments so the throttle and the one-time form tokens are shared.

PostgreSQL verification in this environment: the test database cannot be
built because Windows Application Control blocks `pg_trgm.dll` for
PostgreSQL 18; every migration was applied to the development database
instead and each CHECK constraint, partial unique index and type change was
probed there.

Rollback: migrations `000200`–`000700` drop their own tables (`000300`
refuses while submissions reference a category). `000100` and `000500` keep
their columns: narrowing `client_contact` could truncate encrypted values
and dropping target columns would orphan organization-targeted rows. Export
those rows first; do not force a destructive rollback.
