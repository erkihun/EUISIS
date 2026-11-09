# ADR 0020: The court (court-ms) as a module of EUISIS

- Status: accepted (2026-10-31); step 1 done, step 3 half done (EUISIS → court is direct)
- Supersedes, in part: ADR 0013 (court-ms as a separate system linked by API) and the 2026-10-29
  note that court-ms is the one API-linked system. The owner asked for court-ms to be cloned into
  EUISIS as a real modular-monolith module.
- Context: court-ms (Laravel 12, MySQL `court-ms`, branch `feat/euisis-stack`) already ran on the
  EUISIS stack (Inertia, React 19, the EUISIS kit) and talked to EUISIS over HTTP (Flows 1–7).
  It stays live and untouched until cut-over; nothing in its repository changed.

## Decision

`Modules/Court` holds the whole court system, copied by a script and rewritten for EUISIS:

| court-ms | EUISIS |
|---|---|
| `app/Models` | `Modules/Court/Domain/Models` (54 models, each names its table) |
| Services, Actions, Policies, Notifications, Mail, Listeners, Support | `Modules/Court/Application/*` |
| Controllers, Middleware, Requests, Resources, Support/Ui, View components | `Modules/Court/Http/*` |
| Console commands | `Modules/Court/Infrastructure/Console` (`court:*` names) |
| 133 MySQL migrations | one migration of 77 `court_` tables (`2026_10_31_230000`) + reference rows (`230100`) |
| `users` | `court_staff`: one row per platform account that works in the court, id = `users.id` |
| `roles`, `permissions`, teams | `court_roles`, `court_permissions`, `court_role_user`… (the court's own roles, with the active-role switch) |
| `routes/web.php`, `api.php` | `routes/court.php` under `/court` (names `court.*`), `routes/court-api.php` under `/court/api` |
| `resources/views`, `lang` | `Modules/Court/resources/{views,lang}` as `court::` |
| `resources/js/Pages/Court`, `resources/js/court` | same paths in EUISIS |
| tests | `tests/Feature/Court` (court-ms's own, rewritten) |

- **EUISIS manages accounts, roles, permissions and system settings** (owner, 2026-11-01). The court
  has no Users, Roles, Permissions, System Settings or role-switch screens. Its permissions are EUISIS
  permissions `court.<name>` (`database/seeders/data/court-permissions.php`, 70, in the Court Cases
  category, Amharic labels written for them; `court.judge` replaces the judge role), given by roles
  on the EUISIS Roles page; Super Admin holds them all. An account with any `court.*` permission works
  in the court: `ResolveCourtStaff` makes its `court_staff` row (signature, stamp, teams) the first
  time and puts it on the `court` guard for the request, so the court's policies and audit work
  unchanged. Court roles that court-ms gave (`court_roles`, no screen) still count until the cut-over
  import turns them into EUISIS roles. Password, MFA, sessions, security headers and mail transport
  are the platform's.
- **Applicants and respondents** keep their own sign-in on the `applicant` and `respondent` guards;
  with `COURT_PORTAL_IN_EUISIS` on (Flow 6) the portal is closed as before.
- **Files** live on the `court_private` and `court_public` disks (`storage/app/{private,public}/court`),
  cache keys under `court.`, morph names `court_*`.
- **SQL** court-ms wrote for MySQL (FIELD, DATE_FORMAT, SUBSTRING_INDEX) goes through
  `Application\Support\Sql`. court-ms ran MySQL in non-strict mode, so required text and number
  columns the code leaves out take `''`/0 or NULL, as they effectively did there.
- **Packages**: setasign/fpdi and fpdf (appeal bundles). mews/purifier, simple-qrcode and
  owen-it/laravel-auditing are replaced by small adapters on HTML Purifier, bacon-qr-code and a
  `court_audits` trait.
- **UI**: the court's pages are listed in the EUISIS sidebar's Tribunal Court group, each under its
  `court.*` permission, in the normal EUISIS layout. Only the applicant and respondent portals keep a
  court menu of their own. The group is clustered the way a tribunal registry works: Registry,
  Hearings & inspection, Judgments & appeals, Parties & counsel, Correspondence, Court
  administration, Court public site.
- **What EUISIS owns (2026-11-01)**: users, roles, permissions and system settings, as before, and
  now also reports, performance evaluations (with their settings) and the audit trail. The court's
  own Reports, Performance Evaluations, Performance Evaluation Setting and System Audit screens,
  their routes, models and seven `court.*` permissions are removed
  (`2026_11_01_000200_remove_court_reports_performance_audit_permissions`). Every court record change
  is written to the EUISIS audit log as `court_record.created|updated|deleted|restored` through
  `WriteAuditLogAction`, actor = the platform account (applicants and respondents are named in the
  reason). The `court_performance_*` tables keep their imported rows, unused; `court_system_audits`
  still receives the court's request and sign-in trail, with no screen. Case history
  (`court_case_audits`) stays on the case page. The court's text follows the language EUISIS chose for
  the request; the Amharic court-ms left in English is filled in.

## Still to do

1. **Cut-over data import** (step 2): copy court-ms MySQL rows into the `court_` tables, map `users`
   to platform accounts (by `euisis_employee_uuid`, then email), morph types `App\Models\X` → `court_*`,
   disks `private/public` → `court_private/court_public`, and copy the files.
2. **EUISIS → court: done (2026-10-31).** EUISIS asks the court through its public contract
   `Modules\Court\Contracts\CourtIntegration` (answer: `Contracts\Data\CourtAnswer`), as it asks any
   module. `CourtIntegrationGateway` runs the court's own integration action for the path as PHP (no
   HTTP kernel, middleware, URL, token or network), as the `EUISIS (in process)` integration client.
   `App\Services\CourtMs\CourtMsClient` uses it whenever `COURT_MS_URL` is empty; with a URL and a token
   it still reaches the separate court-ms over HTTP, until the cut-over. Still to do: the court → EUISIS
   direction — the court reads EUISIS employees and organizations over the partner API into its
   `court_euisis_*` mirror tables (`court:euisis-sync`); replace that with `App\Backbone` contracts and
   drop the mirror tables, the sync command and the court's integration API.
3. Five of the 443 court tests fail: the signage board's day window (two tests; EUISIS's app
   timezone is Addis Ababa, court-ms's was UTC), one case-merge query, the API sign-in audit row, and
   the court API tokens of a staff member suspended by the EUISIS staff sync.

## Consequences

The court is one more module: one deploy, one database. Until step 3 it still carries its own copy of
EUISIS directory data and its integration API, which is the debt this ADR tracks.
