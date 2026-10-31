# ADR 0020: The court (court-ms) as a module of EUISIS

- Status: accepted (2026-10-31), step 1 of 3 done
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

- **Staff** sign in to EUISIS. `ResolveCourtStaff` turns the platform account into its `court_staff`
  row on the `court` guard for the request (403 without an active row), so the court's roles,
  permissions, policies and audit work unchanged. Password, MFA, sessions, security headers and mail
  transport are the platform's; their court-ms screens and tests were left out. The court's Users page
  links an existing EUISIS account by email or creates one through `CreateUserAction`.
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
- **UI**: the sidebar shows the court's server-sent menu as a `court` workspace (like Core), with its
  own home, bell feed and a way back to EUISIS.

## Still to do

1. **Cut-over data import** (step 2): copy court-ms MySQL rows into the `court_` tables, map `users`
   to platform accounts (by `euisis_employee_uuid`, then email), morph types `App\Models\X` → `court_*`,
   disks `private/public` → `court_private/court_public`, and copy the files.
2. **In-process EUISIS link** (step 3): the court still reads EUISIS over the partner API
   (`COURT_EUISIS_*`, the `court_euisis_*` mirror tables) and EUISIS still calls court-ms over HTTP
   (`COURT_MS_*`). Replace both with contracts: the court reads `App\Backbone` contracts and Grievance /
   Discipline contracts, and publishes `Modules\Court\Contracts` for My Portal's court cases and the
   tribunal referral, then retire the mirror tables and the integration API.
3. Five of the 439 ported court tests fail: the signage board's day window (two tests; EUISIS's app
   timezone is Addis Ababa, court-ms's was UTC), one case-merge query, the API sign-in audit row, and
   the court API tokens of a staff member suspended by the EUISIS staff sync.

## Consequences

The court is one more module: one deploy, one database. Until step 3 it still carries its own copy of
EUISIS directory data and its integration API, which is the debt this ADR tracks.
