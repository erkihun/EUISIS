# EUISIS Cafeteria platform

The cafeteria subsidy module (providers, networks, access, policies, scanning, ledger, settlements, reports
and the provider portal) as its own system: a separate backend, frontend and database. Decision record:
[ADR 0014](../docs/architecture/adr/0014-cafeteria-platform.md); how the two systems connect:
[`docs/integration/cafeteria-platform.md`](../docs/integration/cafeteria-platform.md).

```
cafeteria-platform/
├── backend/    Laravel 13: the screens (Inertia, session sign-in), the provider portal and the API (/api/v1, tokens); database cafeteriadb
├── frontend/   the EUISIS screens, copied unchanged (React, Inertia, English / Amharic), built into backend/public/build
└── docs/       the integration API contract (OpenAPI)
```

```
 browser ──► backend (:8110 in dev) ── EUISIS pages, EUISIS route names, session sign-in; /provider/portal for operators
                │                   └─ /api/v1/integration/cafeteria for EUISIS (Bearer token, scope cafeteria:read)
                ▼
            cafeteriadb ◄── cafeteria:sync-from-euisis (organizations, units, employees) ◄── EUISIS API
                        ◄── card verification at scan time (GET /api/v1/id-cards/verify/{uuid}) ◄── EUISIS API
```

## What is here and what stays in EUISIS

| On the platform | In EUISIS |
| --- | --- |
| Every cafeteria screen and rule: dashboards, the QR scanner (desktop and mobile), providers, networks, cafeterias, access, assignments, policies with their approval workflow, exclusions, holidays, day rules, special days, transactions, ledger, settlements, reports, analytics, settings | Master data (organizations, employees, placements, ID cards): mirrored here read-only, EUISIS stays the writer |
| The provider portal (sign-in, scan, transactions, exports, menus, orders, ledger, reports, profile) | NFC credentials: a tap on the platform answers "not available" |
| Accounts, roles, settings, audit, security center, integration center, system health (the Core skeleton) | The Situation Room, Intelligence and the employee portal, which read the platform over its API |

Roles: `core_viewer` reads, `core_officer` operates (scans, drafts, submits, exports), `core_admin` does
everything (approvals, settlements, accounts, settings).

## How the copy stays exact

`frontend/scripts/sync-from-euisis.mjs` (`npm run sync`) copies the EUISIS pages it lists with their whole
import closure, the translations, styles and `@euisis/ui`, the EUISIS `lang/` files, and the cafeteria PHP
backend (`BACKEND_DIRS`, `BACKEND_COPIES`) into `backend/`. Only the listed replacements and patches differ
from EUISIS. Run it again whenever EUISIS changes; CI fails when the copy has drifted.

The database schema is generated from a live EUISIS database:

```bash
cd backend
php database/schema-from-euisis.php "pgsql:host=127.0.0.1;port=5432;dbname=aaemployeedb" <user> <password> <tables...> > database/migrations/....php
```

## Run it locally

Backend (PHP 8.4+, Composer, a PostgreSQL or MariaDB database):

```bash
cd cafeteria-platform/backend
composer install
cp .env.example .env            # set DB_*, EUISIS_URL and EUISIS_TOKEN
php artisan key:generate
php artisan migrate
php artisan db:seed             # the cafeteria service type, provider type, day rules and defaults
php artisan core:create-user admin@cafeteria.local "Cafeteria Administrator" --role=core_admin
php artisan serve --port=8110
```

Frontend (Node 22+):

```bash
cd cafeteria-platform/frontend
npm install
npm run sync                     # copy the EUISIS screens and the cafeteria backend
cd ../backend && php artisan ziggy:generate ../frontend/resources/js/ziggy.js && cd ../frontend
npm run build                    # into ../backend/public/build
```

Tests: `php artisan test` in `backend` (SQLite in memory; `DB_CONNECTION=pgsql DB_DATABASE=cafeteriadb_test`
for a PostgreSQL run), `npm run typecheck` in `frontend`.

## Connecting to EUISIS

`php artisan cafeteria:euisis-ping` checks the token; `php artisan cafeteria:sync-from-euisis` mirrors the
directory (`--full` takes everything again). EUISIS checks the other direction with
`php artisan cafeteria-platform:ping`. The steps are in `docs/integration/cafeteria-platform.md`.
