# Institutional Reporting & Compliance

Institutions prepare and submit narrative reports, in the system or as Word files, on configured
schedules. Leadership sees who was required to report, who did, who did not and who was late; what
awaits review, was returned, approved or rejected; which reviewers are overdue; which follow-up
actions are open; and how each institution has complied period by period.

The module serves general institutional reports. It needs none of ID cards, Cafeteria, Transport or
Transfers, and none of them needs it.

Decision record: [ADR 0038](../../docs/architecture/adr/0038-institutional-reporting-module.md).
User instructions in Amharic: [docs/user-guide/am/27-institutional-reports.md](../../docs/user-guide/am/27-institutional-reports.md).

## Where it sits

The application is an incrementally modularized monolith: one Laravel application, one deployment
(web, queue workers, scheduler), one database, one sign-in, one frontend build. Twenty-four modules
live under `Modules/`; the Core (organizations, users, roles, calendar, audit) is still legacy code in
`app/`, published through `App\Backbone\Contracts`. Reporting is an enforced module inside that. It
does not make the rest of the application modular, and this document does not claim it does.

```
Modules/Reporting/
  Contracts/        public API: ReportingCompliance, Data\*, Events\*V1      (the only part others may use)
  Domain/           pure PHP: enums, Ethiopian calendar and periods, timeliness, compliance tally,
                    workflow rules, reminder stages                           (no framework, no database)
  Application/      use cases, queries, ports, the guard, settings            (no HTTP, no Core classes)
  Infrastructure/   Eloquent models, Core adapters, Word renderer, upload inspector, outbox, jobs, commands
  Http/             controllers, form requests, presenters                    (resolve the actor, call a use case)
  Providers/        ReportingServiceProvider: the single entry point
  Database/         migrations (all reporting_* tables)
  Routes/ Config/ Lang/    routes, configuration, Amharic and English server-side text
resources/js/modules/reporting/   pages, components, types and dictionaries of the UI
tests/Unit/Reporting, tests/Feature/Reporting, tests/Feature/Architecture/ReportingArchitectureTest.php
```

Two things differ from the other modules, on purpose:

- Models are in `Infrastructure/Persistence/Models`, not `Domain/Models`, so that `Domain` stays free
  of the framework. Use cases work with the module's own models directly; there is no repository per
  table. Ports exist only where they protect a boundary with another owner.
- The module carries its own migrations, routes, configuration and translations, loaded by its
  provider. `ModuleBoundariesTest` allows those four resource directories for any module.

## Dependencies

```
Reporting ──► App\Backbone\Contracts   AccessScopeReader, OrganizationLookup, OrganizationHierarchy,
                                       WorkingDays, AuditTrail, UserNotifier
          ──► App\Support\RichText     the application's one HTML sanitizer
          ──► ServiceMonitoring\Contracts   optional indicators; resolved lazily, skipped when off or failing
nothing   ──► Reporting internals      other code may use Modules\Reporting\Contracts only
```

No cycle: nothing Reporting depends on depends on Reporting.

| Port (`Application\Ports`) | Adapter | Behind it |
| --- | --- | --- |
| `OrganizationDirectory` | `CoreOrganizationDirectory` | `OrganizationLookup`, `OrganizationHierarchy` |
| `ReportingAccessResolver` | `CoreAccessResolver` | `AccessScopeReader` |
| `WorkingCalendar` | `CoreWorkingCalendar` | `WorkingDays` |
| `AuditRecorder` | `CoreAuditRecorder` | `AuditTrail`, plus the module's own `reporting_events` |
| `NotificationPublisher` | `DeduplicatedNotificationPublisher` | `UserNotifier` |
| `RichTextCleaner` | `PlatformRichTextCleaner` | `RichTextSanitizer` |
| `DocumentRenderer` | `PhpWordDocumentRenderer` | PHPWord 1.4 |
| `PdfConverter` | `LibreOfficePdfConverter` | optional `soffice` binary |
| `UploadInspector` | `UploadedFileInspector` | ZipArchive, optional scanner command |
| `EventOutbox` | `DatabaseOutbox` | `reporting_outbox` |
| `ArtifactQueue` | `DatabaseArtifactQueue` | `reporting_artifacts`, the queue |
| `MetricSource` | `ServiceMonitoringMetricSource` | another module's public contract |

Five of the Core facades (`AccessScopeReader`, `OrganizationLookup`, `WorkingDays`, `AuditTrail`,
`UserNotifier`) were added for this module because the existing ones take a `User` model. They are
narrow, live in the owning subsystem (`app/Backbone`), and are the only code that touches the Core's
models on Reporting's behalf.

Every protected operation receives an `ActorContext` (user id, name, Reporting permissions, reachable
organizations) resolved on the server. An organization id sent by a client is only ever checked
against it.

## Data ownership

Reporting owns every table prefixed `reporting_` (27 tables, one migration) and no other.

| Area | Tables |
| --- | --- |
| Configuration | `reporting_report_types`, `reporting_template_versions`, `reporting_template_sections`, `reporting_schedules`, `reporting_schedule_targets`, `reporting_settings` |
| Periods and expectations | `reporting_cycles`, `reporting_obligations` |
| Content | `reporting_drafts`, `reporting_submissions` (immutable), `reporting_attachments`, `reporting_artifacts` |
| Review | `reporting_review_steps`, `reporting_review_assignments`, `reporting_comments` |
| Deadlines | `reporting_extensions`, `reporting_applicability_decisions` |
| After the report | `reporting_follow_ups`, `reporting_follow_up_updates`, `reporting_consolidations`, `reporting_consolidation_sources`, `reporting_metric_captures` |
| Delivery and history | `reporting_outbox`, `reporting_inbox`, `reporting_notification_deliveries`, `reporting_events`, `reporting_scheduler_runs` |

- Organizations (uuid) and users (bigint) are stored as plain references. **No foreign key leaves the
  module**, so nothing can cascade into or out of another owner's tables, and no relation or join
  crosses the boundary. The price is that the database does not stop a reference to a deleted
  organization; the Core archives organizations rather than deleting them, and Reporting never
  resolves a name through the reference for history.
- History is kept in snapshot columns: the institution's code and names, its ancestors and the
  structure version at publication, the names of the responsible officers, the receiving institution's
  names. A later rename or restructuring changes new periods only.
- **Every moment is stored in UTC**, whatever timezone the application runs in. The application sets
  its runtime timezone from a system setting (Africa/Addis_Ababa in production), and Eloquent would
  otherwise read a stored value in that zone; `ReportingModel` pins both directions. Screens, documents,
  notices and exports show Africa/Addis_Ababa time and name the zone (EAT). Dates of a period are civil
  dates and are not shifted. `BusinessTimezoneTest` runs the workflow under the production timezone.
- Uniqueness is enforced by the database: one cycle per schedule and period, one obligation per cycle
  and subject, one draft and one submission per obligation and version, one delivery per notice and
  person, one outbox row per event id, one inbox row per consumer and event.
- Foreign keys inside the module restrict deletes. The two cascades are template sections and schedule
  targets, which are configuration of their parent.

Roles and permissions are the Core's: the permission catalogue entry is
`database/seeders/data/reporting-permissions.php` and the registration migration is
`database/migrations/2026_11_20_100100_register_reporting_permissions.php`.

## Public API

**Query** — `Modules\Reporting\Contracts\ReportingCompliance::summary(?array $organizationIds, ?string $asOf)`
returns `Contracts\Data\ComplianceSummary` (counts and rates; no narrative). The caller passes only
organizations its own viewer is authorized for.

**Permissions and roles** — `Contracts\Data\ReportingRoles` publishes the 14 permission names and the
sets of the six default roles.

**Integration events** — final, readonly classes in `Contracts\Events`, versioned by class name:

| Event | Name | When |
| --- | --- | --- |
| `ReportSubmittedV1` | `reporting.report_submitted` | a version is submitted (first or resubmission) |
| `ReportApprovedV1` | `reporting.report_approved` | the last review step approves |
| `ReportingDeadlineMissedV1` | `reporting.deadline_missed` | a deadline passes with no submission; once per obligation |

Each carries `eventId`, `occurredAt` (ISO 8601, UTC) and identifiers. No event carries narrative text.
A consumer listens with `Event::listen(ReportSubmittedV1::class, …)`.

## Who owes a report

A schedule names its reporting subjects. A subject is one of three things, told apart by the
obligation's `subject_key`:

| Subject | Key | Who reaches it on the reporting side |
| --- | --- | --- |
| An institution | `org:<id>` | anyone whose scope reaches the institution, and the named officers |
| A work unit inside an institution | `unit:<id>` | the same: scope on the unit's institution, and the named officers |
| A named person | `user:<id>` | that person and the named officers only |

**A work unit reports inside its own institution.** `ManageSchedules::resolveTargets()` accepts a unit
only when the institution that receives the reports is the unit's institution
(`unit_outside_institution` otherwise), and only while the unit is active there (`unknown_unit`). The
unit's reports are therefore received, reviewed and consolidated by that institution, and nobody whose
scope lies outside it sees them. The unit's code and names are copied onto the obligation when the
period is published, like the institution's. A unit closed later owes no new report; what it already
owes stays. `ConsolidateReports` takes the parent's own units as sources next to the institutions
under it, and the register, the matrix and the export carry the unit.

The Core has no unit-level access scope, so inside one institution a person whose scope covers the
institution sees every unit's report. To confine someone to one unit, give them no institution scope
and name them as the unit's accountable or preparing officer.

## Workflow and the rules that protect the record

Not started → Draft → Submitted → Under review → Approved / Returned for revision / Rejected.

Five facts are kept apart and never folded into one status:

| Fact | Where |
| --- | --- |
| Workflow status | `reporting_obligations.workflow_status` |
| Initial-submission timeliness | computed from `first_submitted_at` and the deadline, never stored |
| Revision compliance | `revision_due_at` against the resubmission |
| Reviewer timeliness | `reporting_review_steps.due_at` |
| Applicability | `applicability`, with dated decisions |

- **On time** is a first submission at or before the effective deadline (the exact instant is on time).
  **Late** is a first submission after it. **Overdue** is a passed deadline with none. A draft or an
  uploaded file is not a submission.
- `first_submitted_at` is written once and never again. Returns, resubmissions and a slow review cannot
  change it.
- A submitted version is immutable; the model refuses any change but its review status, and any delete.
  A return or a reopening opens a new version and leaves the old one as it was.
- A reviewer cannot decide a version they submitted, unless the report type allows it. A person cannot
  grant their own extension request.
- Returning, rejecting, extending, exempting, cancelling, reopening and reassigning need a reason.
- An extension request alone changes nothing. A granted one moves `effective_due_at`; `original_due_at`
  and the "deadline missed" stamp stay. One granted after the deadline it replaces is labelled
  retrospective. Compliance against the original deadline is always reported beside the adjusted one.
- A Nil Report is a submission that is reviewed. It is not an exemption.

### Metrics

For an as-of time, *due-required* obligations are the applicable ones whose effective deadline has passed.

- Submission rate = due-required with a valid submission / due-required
- On-time rate = due-required first submitted by the deadline / due-required
- Approval rate = approved / obligations ever submitted

A zero denominator shows as N/A. Each obligation counts once whatever its versions. Early submissions
for future deadlines are shown separately. The register page, the dashboard figures, the
institution–period matrix, the trend and the export all start from `ComplianceQuery::base()` and
classify with the same domain classes, which is why they reconcile; the screens say which groups of
figures add up and that categories of different groups overlap.

With a past as-of date, each obligation is seen with the deadline and applicability in force then and
only the submissions made by then. The finer workflow step is known only as of today and is labelled so.

## Transaction boundaries

| Operation | In one database transaction | After the commit |
| --- | --- | --- |
| Publish a period | cycle (unique per schedule and period) and one obligation per target (`insertOrIgnore` on the unique key), audit | — |
| Save a draft | obligation row lock, lock-version check, draft, first move to Draft | — |
| Submit | obligation row lock, authorization, validation, immutable submission with server time and reference, files attached to the version, review steps, obligation facts, audit, **outbox row**, **artifact request row** | relay job; Word generation job |
| Decide a review step | obligation and submission locks, the step, next step or final status, new version on return, comment, audit, outbox row on approval, artifact request | relay job; generation job; notice to the submitter |
| Decide an extension / applicability | obligation lock, the dated decision, the effective deadline or applicability, audit | — |
| Compile a consolidation | consolidation lock, sources replaced, missing list, indicators, metric captures, artifact request | generation job |
| Mark a missed deadline | per obligation: re-read under lock, stamp, outbox row | relay job |

Submission never waits for the Word file and is never undone by it. A retry of a submit request that
already succeeded returns the existing submission.

## Reliable delivery

Dispatch-after-commit alone loses a message if the process dies between the commit and the queue. So:

- An integration event is a row in `reporting_outbox`, written in the same transaction as the fact. A
  relay job is dispatched after the commit, and `institutional-reporting:run outbox` relays whatever is
  still pending every five minutes. A failing row is retried with a growing delay, eight times, then
  left as `failed` for a person.
- A generated file is a row in `reporting_artifacts` (queued → processing → completed / failed), also
  written with the fact. `institutional-reporting:run artifacts` re-queues rows whose job was lost,
  stuck, or failed fewer than five times.
- Delivery is **at least once**, not exactly once: a crash after dispatch and before the row is marked
  sends the event again. Consumers must be idempotent on `eventId`. The module's own consumer uses
  `InboxGuard` (one row per consumer and event) and every notice has a unique delivery key per person.

Reminders run on three separate tracks: submission, revision and review. Defaults are 7, 3 and 1 day
before, on the due date, once overdue, and an escalation after 3 working days; a schedule may carry its
own. Each run sends only the latest stage that is due, so a scheduler that was down sends one notice on
recovery, not the backlog. A track stops by itself when its condition is resolved. The module records
that a notice was handed to the bell or the mail system; it never claims a message was read.

## Documents

- Structured reports and consolidated reports are generated as real `.docx` files by PHPWord: header
  with the institution (and a logo when `REPORTING_LOGO_PATH` is set), title, period, institution,
  reference number, version, record identifier, section headings, tables, lists, annex list, the
  prepared / reviewed / approved block with the names and times the workflow recorded, a label (draft,
  submitted, approved), and page numbers in the footer.
- Amharic is written as UTF-8 text and the document font (setting, default Nyala) is named for all
  scripts. The font is named, not embedded: a machine without it shows the text in its own Ethiopic font.
- The sign-off block is text and says so. Nothing is signed cryptographically and no signature image is
  drawn. The "content fingerprint" on a version is a SHA-256 of its content, shown as evidence that the
  version did not change; it is not a signature.
- An uploaded Word report is kept byte for byte. Its narrative completeness is declared by the
  submitter and verified by the reviewer; the system does not claim to read it.
- PDF preview is optional (`REPORTING_SOFFICE_PATH`). Only documents the module generated itself are
  converted, never an upload, in a throwaway profile with a time limit. Without it, Word download works
  unchanged.

## Security

- Fourteen separate permissions: view, prepare, submit, review, approve, reopen, publish schedules,
  manage templates, grant extensions and exemptions, consolidate, export, read confidential reports,
  inspect operations, manage settings. A permission is never enough alone: the actor must also reach the
  organization on the reporting or the receiving side (`Application\Support\ReportingGuard`).
- The same guard scopes queries, counts, matrices, exports, files, pick-lists and notifications.
- Files are on a private disk under generated names. Every download is authorized again at that moment
  and audited.
- Uploads are inspected before they are kept: real OOXML structure, entry count, uncompressed size and
  compression ratio (archive bombs), no macros, no embedded objects or ActiveX, no external
  relationships other than plain hyperlinks, no DOCTYPE or entities. XML is searched as text and never
  parsed with entity loading. Extension and declared type are not trusted.
- Narrative HTML is sanitized on save with the application's allowlist and again when rendered. The
  Word writer understands a fixed set of tags and never fetches an image, frame or link.
- CSV rows pass through `csv_safe_row()`; Excel cells are written explicitly as text.
- Audit: decisions and disclosures go to the application audit log (append-only for the application,
  restricted access) and to the report's own history. This does not protect against a database
  administrator, and the module does not say it does.

## Operations

```
php artisan institutional-reporting:run            # all tasks; or one of: cycles deadlines reminders outbox artifacts
php artisan institutional-reporting:health         # exit code 1 when something needs a person; --json for monitors
```

| Task | Schedule | Idempotent because |
| --- | --- | --- |
| `cycles` | hourly | unique keys on cycle and obligation; walks periods from the last one published, so it catches up |
| `deadlines` | every 15 minutes | stamps each obligation once, under a row lock |
| `reminders` | daily 07:00 EAT | one delivery row per notice and person |
| `outbox` | every 5 minutes | rows are claimed under a lock and marked dispatched |
| `artifacts` | every 10 minutes | a row is claimed under a lock before work starts |

**Maintenance switch** (`maintenance` setting, or `REPORTING_MAINTENANCE=true`): every write route
answers "paused", scheduled tasks record a `paused` run and do nothing, and queued jobs return without
working. Data stays readable. Nothing is lost: outbox rows stay pending and artifact rows stay queued,
and the scheduled tasks pick them up when the switch is turned off. The settings page is the one write
that stays open, since it is how the module is switched back on. This pauses the module; it does not
unplug it. Its tables, provider and routes stay loaded.

**Feature flag** (`FEATURE_REPORTING=false`): every route answers 404 and the navigation hides the
module. The scheduled tasks keep running unless the maintenance switch is also on.

Production requirements:

- A queue worker and the scheduler (`schedule:run` every minute) must be running. Without the worker,
  submissions still succeed, but Word files stay "waiting" and events stay pending; the health page
  shows both.
- PHP extensions `zip`, `dom`, `xml`, `mbstring`.
- A private filesystem disk (`REPORTING_DISK`, default `local`). Back it up with the database.
- **A malware scanner is not bundled.** Set `REPORTING_SCANNER` (for example
  `clamdscan,--no-summary,--fdpass`; exit code 0 clean, 1 infected) and consider
  `REPORTING_SCANNER_REQUIRED=true`. Without it files pass structure checks only and are marked
  "not scanned" on screen.
- An Ethiopic font on the machines that open the documents (and on the server if PDF preview is used).
- Mail configured, if `email_notifications` is switched on.

## Deployment and migration

Additive only: new tables, new permissions, new roles. No existing table is altered and no data is moved.
`2026_11_21_100000_add_work_units_to_reporting` adds nullable unit columns to two of the module's own
tables; existing rows keep NULL and stay institution or personal reports.

```bash
composer install --no-dev --optimize-autoloader      # brings phpoffice/phpword ^1.4 (and phpoffice/math)
php artisan migrate --force                          # creates reporting_* tables, registers permissions and six roles
php artisan routes:export-js && npm ci && npm run build
php artisan optimize:clear && php artisan optimize
php artisan queue:restart
php artisan institutional-reporting:health
```

Then, in the application: give people the roles (Report Preparer, Reporting Officer, Report Reviewer,
Report Approver, Reporting Oversight Officer, Reporting Administrator) with their organization scope;
create a report type and publish its template; create a schedule and check its period preview.

Rollback: `php artisan migrate:rollback` drops the `reporting_*` tables and so the reports in them;
take a backup first. Permissions and roles are deliberately kept by the rollback. To switch the module
off without touching data, use `FEATURE_REPORTING=false`.

The migration uses `dateTime` for mandatory moments and explicit index and key names under 64
characters, as the MySQL server requires. It ran on SQLite in the test suite and on the local MariaDB
10.4 database; it has not been run on the live server's MySQL version (see Limitations).

## Tests

```bash
php vendor/bin/pest tests/Unit/Reporting tests/Feature/Reporting
php vendor/bin/pest tests/Feature/Architecture
npx tsc --noEmit -p . && npm run i18n:audit
```

| File | Covers |
| --- | --- |
| `tests/Unit/Reporting/ReportingDomainTest.php` | Ethiopian calendar, Pagume and leap years, periods without gaps, exact-deadline timeliness, tally and denominators, workflow guards, reminder stages |
| `ObligationsTest.php` | obligations created exactly once, scheduler catch-up, missing institutions, snapshots surviving a rename, reassignment history |
| `SubmissionWorkflowTest.php` | both modes end to end, exact deadline, review delay, resubmission keeping first timestamps, immutability, retries and stale editors, Nil Report, self-review, failed rendering |
| `DeadlinesAndComplianceTest.php` | extensions and original compliance, retrospective label, exemptions and denominators, as-of, dashboard = register = export = trend = matrix = contract, follow-ups, consolidation |
| `DocumentsTest.php` | Amharic, lists and tables inside the generated `.docx`; fifteen refused uploads; scanner behaviour |
| `OutboxAndRemindersTest.php` | outbox relay, duplicate delivery, retry and failure visibility, missed deadlines once, three reminder tracks, missed-run recovery, maintenance switch, optional modules off or failing |
| `HttpAccessTest.php` | every page, cross-scope pages / files / counts / exports denied, confidential reports, autosave conflict, uploads, CSV and Excel formula injection, special reports, adapter contracts with the Core |
| `WorkUnitsTest.php` | a work unit as the reporting subject: one obligation per unit, received by its own institution, refused outside it, hidden from other institutions, on its own row of the matrix, a source of the institution's consolidation |
| `BusinessTimezoneTest.php` | moments stored in UTC and read back as the same instant with the application in Addis Ababa time; timeliness, filters and calendar on the instant |
| `Architecture/ReportingArchitectureTest.php` | pure Domain, Application behind ports, Core and other modules behind adapters, table ownership, no cross-owner relations, outside code through Contracts only, frontend import boundary |

What the architecture checks cannot prove: they read source text and `use` graphs. A table reached
through a name built at run time, or SQL assembled dynamically, would escape them. Ownership is
therefore also covered by the behaviour tests and by review.

## Limitations

- **Work units.** A special (one-off) report cannot yet name a work unit; use a schedule. There is no
  filter by unit in the register (the search box matches the unit's name), and no unit-level access
  scope (see "Who owes a report").
- **The automated suite runs on SQLite.** The migration was applied to the local MariaDB 10.4 database
  and one full pass of the workflow was made there through the browser (see below), but the suite itself
  has not been run on MySQL or MariaDB, and nothing has been run on the live server's MySQL version. Run
  the suite on a copy of the production database before deploying.
- **The architecture suite runs close to its memory limit.** `tests/Feature/Architecture` completed with
  this module (741 passed) but the process peaked near 2 GB, the limit set in `phpunit.xml.dist`. The
  shared `arch()` rules grow with every module; another module, or CI's other Shard 1 directories in the
  same process, may push it over. Not measured in CI.
- **Concurrency is tested as retries and stale writes, not as truly parallel requests.** The design
  relies on row locks and unique keys, which SQLite does not exercise the way MySQL does.
- **Browser check: one pass, in Amharic, at 1024 px.** Walked on a scratch copy of the local database:
  assignments, preparing a structured report with autosave, submitting, the report page with its receipt
  and generated Word file, the review inbox, approving both steps, the oversight overview, the register
  and the schedule form's period preview. It found and fixed three defects (moments read in the runtime
  timezone, a lost-edit race between sections, cramped figures). **Not** clicked through: the English
  interface, Word upload, extensions and exemptions, follow-up actions, consolidation, the type and
  template form, the special-report form, the calendar, settings, phone widths, a screen reader. Those
  are covered by HTTP tests of their data only.
- **Reports assigned to a person** are supported by the model, publication and guard, but the schedule
  form adds institutions only.
- **As-of reporting** reconstructs deadlines, applicability and submissions; the finer workflow step is
  today's.
- **Hierarchy snapshots** record ancestors and the structure version at publication. Consolidation uses
  the structure in force when it is compiled.
- **Consolidated indicators** are counts of obligations plus whatever optional sources return. Numbers
  written inside narratives are not extracted or summed.
- **Section comments** are attached to a section, not to a position inside its text.
- **Excel export** builds the workbook in memory; it is refused above 50,000 rows. CSV streams.
- **SMS** is not implemented. In-app notices always, e-mail when switched on.
- **No scanner, no PDF converter and no logo** are configured by default.
