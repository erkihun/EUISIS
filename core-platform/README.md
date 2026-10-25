# EUISIS Core platform

The Golden Path (Organization → Unit → Position → Employee → Assignment → ID Card)
as its own system: a separate backend, frontend and database. Decision record:
[ADR 0006](../docs/architecture/adr/0006-standalone-core-platform.md).

```
core-platform/
├── backend/    Laravel 13 API, /api/v1, token sign-in, database euisis_core
└── frontend/   Vite + React single-page app in the EUISIS layout (English / Amharic), talks only to the API
```

```
 browser ──► frontend (static files, :5174 in dev)
                │  /api (Bearer token)
                ▼
            backend (:8100 in dev) ──► euisis_core database (MariaDB/MySQL or PostgreSQL)
                │
                └─ core:import-from-euisis (read only) ──► EUISIS database
```

## What the Core enforces

| Rule | Where |
|---|---|
| Organization scope = WHERE: a scoped account sees its organization and everything under it in the published hierarchy; an account with no scope organization sees the whole city | `app/Services/OrganizationScope.php`, every read and write |
| A position takes holders up to its approved seats (1 when no establishment is approved) | `app/Services/SeatCapacity.php` |
| One current placement per employee; a transfer closes the old one the day before the new one starts; a separated employee cannot be transferred | `app/Actions/TransferEmployee.php` |
| Card changes follow suspend / reinstate / revoke from allowed states only, with a reason; reinstating needs an active employee and no other active card | `app/Actions/ChangeCardStatus.php` |
| A card is valid only when active, current, QR active, not revoked, not expired and the employee is not separated | `app/Models/IdCard.php` |
| Every change is audited with actor, request id, IP and user agent | `app/Services/AuditWriter.php`, table `core_audit_logs` |
| Personal fields never leave through the API; card verification answers validity, status, number and name only | `app/Models/Employee.php`, `CoreReadController::verifyCard` |

Roles: `core_viewer` (read), `core_officer` and `core_admin` (read and change).

## Run it locally

Backend (PHP 8.4+, Composer, a MariaDB/MySQL database named `euisis_core`):

```bash
cd core-platform/backend
composer install
cp .env.example .env          # set DB_* and, for the import, EUISIS_DB_*
php artisan key:generate
php artisan migrate
php artisan core:create-user admin@example.org Core Administrator --role=core_admin
php artisan serve --port=8100
```

The last command but one prints a one-time password. Scoped accounts:
`--organization=<organization code>`.

Frontend (Node 24):

```bash
cd core-platform/frontend
npm install
npm run dev                   # http://localhost:5174, proxies /api to :8100
```

Tests and checks:

```bash
cd core-platform/backend && php artisan test && vendor/bin/pint --test
cd core-platform/frontend && npm run typecheck && npm run build
```

CI runs the same in `.github/workflows/core-platform.yml`.

## The frontend is the EUISIS layout

The Core frontend looks and behaves like the EUISIS Core workspace: the same
sign-in stage, sidebar (sections, groups, menu filter, icon rail), header (page
search with Ctrl K, theme, EN/አማ, account menu), heading band with
breadcrumbs, tonal cards, tables, filters, dialogs and toasts. It is a copy,
not a link, so the Core builds and deploys on its own:

| Core frontend | Copied from EUISIS |
|---|---|
| `src/ui/` | `packages/euisis-ui/src/` (the shared UI kit and `tokens.css`) |
| `src/index.css`, `src/fonts.css` | `resources/css/app.css`, `resources/css/fonts.css` (paths adjusted) |
| `src/components/NavIcons.tsx`, `UserAvatar.tsx`, `auth/LoginStage.tsx`, `src/lib/theme.ts` | the files of the same name, unchanged |
| `src/components/AppSidebar.tsx`, `AppHeader.tsx`, `Breadcrumbs.tsx`, `PageHeader.tsx`, `AppCommandPalette.tsx`, `src/layouts/AuthenticatedLayout.tsx`, `src/pages/Login.tsx`, `src/pages/Dashboard.tsx` | the EUISIS components, with Inertia routing and System Settings replaced by the Core's hash routes and the shipped defaults |
| `src/i18n/en.ts`, `am.ts` (`nav`, `common`, `auth`, `core`) | `resources/js/i18n/{en,am}/` |
| `src/navigation.ts` | `App\Services\Core\CoreNavigation`, limited to the Core pages |

When the EUISIS design changes, copy the changed files again; the unchanged
ones can be copied over as they are.

## API

All routes are under `/api`, JSON, `Authorization: Bearer <token>` except sign-in.

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

## Production notes

- Serve `frontend/dist` and the backend under one host (`/api` to Laravel) or
  set `VITE_API_BASE_URL` at build time and `CORE_FRONTEND_ORIGINS` on the
  backend for CORS.
- `APP_ENV=production`, `APP_DEBUG=false`, HTTPS only. `migrate:fresh`,
  `db:wipe` and friends refuse to run in production.
- Back up `euisis_core` from the cutover on; EUISIS backups no longer cover it.
- Sign-in tokens last 8 hours; deactivating an account (`is_active = false`)
  stops it at the next request.
