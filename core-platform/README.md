# EUISIS Core platform

The Golden Path (Organization → Unit → Position → Employee → Assignment → ID Card)
as its own system: a separate backend, frontend and database. Decision record:
[ADR 0006](../docs/architecture/adr/0006-standalone-core-platform.md).

```
core-platform/
├── backend/    Laravel 13: the screens (Inertia, session sign-in) and the API (/api/v1, tokens); database euisis_core
└── frontend/   the EUISIS screens, copied unchanged (React, Inertia, English / Amharic), built into backend/public/build
```

```
 browser ──► backend (:8100 in dev) ── EUISIS pages, EUISIS route names, session sign-in
                │                   └─ /api/v1 for EUISIS and partner systems (Bearer token)
                ▼
            euisis_core database (MariaDB/MySQL or PostgreSQL)
                ▲
                └─ core:import-from-euisis (read only) ◄── EUISIS database
```

## What the Core enforces

| Rule | Where |
|---|---|
| Organization scope = WHERE: a scoped account sees its organization and everything under it in the published hierarchy (the `reports_to` / `structural_parent` edges, as the city's hierarchy is recorded); an account with no scope organization sees the whole city | `app/Services/OrganizationScope.php`, every read and write |
| Organizations: a blank code is numbered; the parent is the edge in the published hierarchy; no organization under its own descendant; a scoped account works inside its subtree and cannot move its own top organization | `app/Actions/SaveOrganization.php` |
| Units: inside the organization, parent unit in the same organization and never below itself, code unique per organization; a unit with active positions stays active | `app/Actions/SaveOrganizationUnit.php` |
| Positions: organization from the unit, unit in that organization; a held position cannot be retired or moved to another organization; holders follow their seat to a new unit (ADR 0005) | `app/Actions/SavePosition.php` |
| A position takes holders up to its approved seats in force (the Workforce setting when none is approved); an establishment ends, never below its holders | `app/Services/SeatCapacity.php`, `app/Actions/SaveEstablishment.php` |
| One current placement per employee; a transfer closes the old one the day before the new one starts; a separated employee cannot be transferred | `app/Actions/TransferEmployee.php` |
| The employee number never changes. Ending employment (retired, terminated, deceased, transferred out) closes the placement today and suspends the employee's active cards; returning reopens the last position if it has a free seat (ADR 0002) | `app/Actions/UpdateEmployee.php` |
| Cards: one pending request per active employee; approval by someone other than the requester; one live card per employee; approval gives the number, public reference and expiry; then print → issue → activate; lost/damaged/suspended/revoked/expired cards are replaced and linked | `app/Actions/CardRequests.php`, `app/Actions/ChangeCardStatus.php` |
| A card is valid only when active, current, QR active, not revoked, not expired and the employee is not separated | `app/Models/IdCard.php` |
| Every change is audited with actor, request id, IP and user agent | `app/Services/AuditWriter.php`, table `core_audit_logs` |
| Personal fields never leave through the API; card verification answers validity, status, number and name only | `app/Models/Employee.php`, `CoreReadController::verifyCard` |
| The nine EUISIS integrity checks (Data Quality) run over the Core tables | `app/Services/IntegrityChecks.php` |

Roles: `core_viewer` (read), `core_officer` (read and change master data), `core_admin` (also accounts, data quality and settings).

### Screens

Organization Structure (organizations with detail and children, organization
types, units, unit types, the hierarchy tree), Positions (with seats,
holders and establishments), Employees (register, details, employment
status, transfer, card request), Assignments, ID Cards (the full card
lifecycle), Card Requests (approve, reject, cancel), Card Verification,
Users, Audit Logs, Data Quality and Settings. Not in the Core: organizational
change requests, grade levels, occupations, employee import and self-service
corrections, print batches and card templates, NFC credentials and the
integration center; they stay in EUISIS.

## Run it locally

Backend (PHP 8.4+, Composer, a MariaDB/MySQL database named `euisis_core`):

```bash
cd core-platform/backend
composer install
cp .env.example .env          # set DB_* and, for the import, EUISIS_DB_*
php artisan key:generate
php artisan migrate
php artisan core:create-user admin@example.org Core Administrator --role=core_admin --citywide
php artisan serve --port=8100
```

The last command but one prints a one-time password. Every account needs an
explicit scope (ADR 0008): `--citywide`, or `--organization=<organization code>`
(with everything under it; add `--self` for that organization only). An account
without one signs in but reaches nothing. Administrators and officers set up an
authenticator app (TOTP) at their first sign-in; `core:reset-mfa <email>
--reason=...` resets a lost one from the server.

Screens (Node 24). The build goes into `backend/public/build`; the backend
serves the pages at http://127.0.0.1:8100:

```bash
cd core-platform/frontend
npm install
npm run build                 # or: npm run dev (hot reload through backend/public/hot)
```

Sign in at http://127.0.0.1:8100/login with the account from `core:create-user`.

Tests and checks:

```bash
cd core-platform/backend && php artisan test && vendor/bin/pint --test
cd core-platform/frontend && npm run typecheck && npm run build
```

CI runs the same in `.github/workflows/core-platform.yml`.

## The screens are the EUISIS screens

Every Core screen is the EUISIS page itself, not a look-alike: the same React
files, layout, sidebar, header, tables, forms, dialogs and translations. The
backend renders them with Inertia under the EUISIS route names, paths and
permission names, and gives each page the props its EUISIS controller gives
it, read from the Core tables.

- `frontend/scripts/sync-from-euisis.mjs` copies them: it follows the Core
  pages (its `PAGES` list) and the EUISIS entry through every import and copies
  each file from `resources/js` unchanged, with `resources/css`,
  `packages/euisis-ui` and `lang/` (into `backend/lang`). Run `npm run sync`
  after EUISIS changes; CI fails when the copy has drifted.
- The one difference is a patch the script applies to `app.tsx`: a link to a
  page that stays in EUISIS (print batches, transfers, NFC…) resolves to `#`
  instead of failing, because the Core route list does not have it.
- `backend/routes/web.php` holds the routes, `app/Http/Controllers/Web` the
  page controllers, `app/Http/Middleware/HandleInertiaRequests.php` the shared
  props (the Core workspace navigation, the settings in the EUISIS keys).
- Core roles carry EUISIS permission names (`app/Support/CorePermissions.php`),
  so the pages show the same buttons EUISIS shows for the same rights.
- `php artisan ziggy:generate ../frontend/resources/js/ziggy.js` after a
  route change.

| Core screen | EUISIS page |
|---|---|
| Dashboard, Assignments, Audit Center, Data Quality, Settings | `Core/*`, `SystemSettings/Index` |
| Organizations, types, units, unit types, hierarchy (read only) | `Organizations/*`, `OrganizationTypes/*`, `OrganizationUnits/*`, `OrganizationUnitTypes/*`, `HierarchyVersions/*` |
| Positions, position status, establishments | `Positions/*`, `PositionEstablishments/*` |
| Employees | `Employees/*` |
| ID cards, card requests, card check | `IdCards/*`, `CardRequests/*`, `Public/IdChecker` |
| Users, Audit Logs, profile, sign-in | `Users/*`, `AuditLogs/Index`, `Profile/Edit`, `Auth/Login` |

"Exit Core" opens `EUISIS_APP_URL` when set, otherwise it signs out.

## API

For EUISIS and partner systems. All routes are under `/api` (`GET /api`
describes the service), JSON, `Authorization: Bearer <token>` except sign-in.

| Method | Path | Who |
|---|---|---|
| POST | `/auth/login` | anyone (5 failed tries per minute per e-mail and IP) |
| GET | `/auth/me`, POST `/auth/logout` | signed in |
| GET | `/v1/dashboard` | signed in |
| GET | `/v1/organizations`, `/v1/organizations/{id}`, `/v1/organization-units`, `/v1/positions?occupancy=vacant\|held` | signed in |
| GET | `/v1/employees`, `/v1/employees/{id}`, `/v1/assignments?history=1`, `/v1/id-cards?status=` | signed in |
| POST | `/v1/cards/verify` `{card_uuid}` | signed in |
| GET | `/v1/audit?action=&request_id=` | signed in |
| POST | `/v1/employees` | officer, admin |
| POST | `/v1/employees/{id}/transfer` `{position_id, effective_date, reason?}` | officer, admin |
| POST | `/v1/id-cards/{id}/suspend\|reinstate\|revoke` `{reason}` | officer, admin |

Lists are paginated (25 a page) and take `search`. Every response carries
`X-Request-ID`; send your own to trace a request into the audit log.

## Moving the data from EUISIS

1. Point `EUISIS_DB_*` in `backend/.env` at the EUISIS database (PostgreSQL or
   MySQL; a replica or restored copy is best). The import only reads it.
2. Copy: `php artisan core:import-from-euisis`. Identifiers are kept as they
   are: UUIDs, employee numbers, card numbers, public card references. It
   refuses a Core database that already holds rows unless `--fresh-tables`.
3. Prove the copy:
   ```bash
   # in EUISIS
   php artisan core:snapshot --output=euisis.json
   # in core-platform/backend
   php artisan core:snapshot --compare=euisis.json
   ```
   Any table marked `DIFFERENT` stops the move.
4. Cutover: set `CORE_MASTER_DATA_WRITES=cutover` in EUISIS (its master data
   becomes read only), repeat steps 2–3 with `--fresh-tables`, then open the
   Core platform for writes. Rollback: set EUISIS back to `core`; changes made
   in the Core platform since the cutover are in `core_audit_logs`.

Checked on 2026-10-25: the local EUISIS PostgreSQL database copied into a
MariaDB `euisis_core` with every one of the 14 tables matching the EUISIS
snapshot.

## Core settings

Administrators (`core_admin`) change the Core from **Governance › Settings**
(the EUISIS System Settings page; Numbering and Workforce are on its General tab).
Every change is validated, applies at once and is audited (`settings.updated`
with the old and new values). Keys, labels, defaults and limits follow the
EUISIS System Settings wherever EUISIS has the same setting
(`app/Settings/CoreSettingsRegistry.php`).

| Group | Settings | Used by |
|---|---|---|
| General | brand name (English, Amharic), official short name, official names, organization name, environment label, sign-in messages | sign-in page, sidebar, page titles, the environment badge |
| Language | default language | first visit on a device |
| Appearance | default theme, colours (primary, secondary, accent, sidebar), button style, card radius, table density, sticky headers, animations, breadcrumbs, language switcher, theme switching, compact sidebar, logo position, page size | the whole shell; page size sizes every API list |
| Security | session length, wrong-password allowance and lockout, API and card-check limits per minute, maintenance banner | sign-in, rate limits, the banner on every page |
| ID cards | city and bureau names | sign-in page, sidebar |
| Workforce | seats for a position without an approved establishment | registration and transfer seat checks |

Values not changed stay on their defaults (no seeding). `GET /api/settings/public`
gives the frontend the public ones before sign-in; security and workforce
values are never public. Infrastructure stays in `.env` (database, CORS
origins, `APP_KEY`).

## Production notes

- Build the screens (`npm ci && npm run build` in `frontend/`, after
  `composer install` in `backend/`) and serve `backend/public` as the web root.
  Sessions are in the database (`SESSION_DRIVER=database`).
- `APP_ENV=production`, `APP_DEBUG=false`, HTTPS only. `migrate:fresh`,
  `db:wipe` and friends refuse to run in production.
- Back up `euisis_core` from the cutover on; EUISIS backups no longer cover it.
- Browser sessions end after Core settings › Security › session length
  without activity; API tokens last 8 hours. Deactivating an account
  (`is_active = false`) stops it at the next request.
- Run the scheduler (`php artisan schedule:work`, or `schedule:run` every
  minute from cron): it delivers webhooks every minute and marks expired cards
  daily. `GET /api/v1/system-health` (city-wide administrators) shows when
  each last ran, with the database, cache, storage, queue, webhook, API and
  access checks; backups are reported as unknown because the Core cannot see them.
- Set `NATIONAL_ID_HASH_KEY` to the EUISIS value so national-ID duplicate
  checks compare imported and new records (the Core stores only the hash).

## Integrations, events and governance (ADR 0007)

- Consuming systems use **API clients** with least-privilege scopes, an owner
  organization, an IP allow list, a rate limit and an expiry; secrets are shown
  once and can be rotated or revoked. They read `/api/v1/integration/*` and
  check cards; they never write. Developer guide:
  [docs/integration-api.md](docs/integration-api.md).
- Every published change becomes an event in the same transaction (outbox) and
  is delivered to webhooks signed with HMAC-SHA256, retried with back-off.
- Hierarchy versions go draft → review → approval by a second city-wide
  administrator → publication; the structure of any earlier day stays
  queryable (`GET /api/v1/hierarchy?on=YYYY-MM-DD`).
- One-time passwords must be replaced at first sign-in (password policy in
  Core settings › Security); the last city-wide administrator cannot be removed.
- Registration checks for duplicates (national-ID hash, name and date of birth,
  phone, e-mail) and never merges records automatically.
- Tests run on SQLite by default; run them on PostgreSQL too before a release:
  `DB_CONNECTION=pgsql DB_DATABASE=<scratch database> php artisan test`.
