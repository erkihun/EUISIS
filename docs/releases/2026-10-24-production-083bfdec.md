# Release 2026-10-24, production `083bfdec`

Built from `main` `3baaa4ef`; previous production on the remote: `116792f7`
(from `main` `d10faef`). 68 commits. Release type: **MAJOR** (platform upgrade
to Laravel 13, new Core workspace, access-model change). Pushed to the
`production` branch on 2026-10-24 (fast-forward); deployment to a host is done
by operations and is not part of this note.

## Features

- Core workspace (`/core`): dashboard, Assignments, Integration Center, Audit Center, Settings, and a Core sign-in screen over the same authentication (ADR 0002).
- Golden Path contracts and commands: read contracts for organizations, units, positions, employees and credentials; one command moves an assignment; employment ending ends the assignment and frees the seat (ADR 0002).
- Partner API v1 additions (OpenAPI 1.3.0 → 1.4.0): assignments, an employee's assignments, card status by public UUID, verifications; every partner route documented.
- Integration events: `position.occupied`, `position.vacated`, `id_card.activated`, `id_card.suspended`, `id_card.expired` (additive).
- Go-live gates in `production:readiness` (failover drill, restore drill against RPO 15 min / RTO 1 h and 4 h, penetration test, UAT, governance roles, retention), `data:retention-report`, retention matrix (ADR 0004).
- Operations commands: `core:smoke`, `core:snapshot`, `audit:seal`, `audit:verify`, `access:scope-gaps`, `backbone:audit-integrity`, `backbone:duplicate-masters`.
- Product renamed Agelgay · አገልጋይ; new sign-in page.

## Bug fixes

Card replacement records link the new card; officers submit card requests and
a different person approves; Situation Room date expressions on MySQL/MariaDB;
transport manual-exception lookup selects existing columns; Intelligence
request buckets stored in the application timezone; employee registration and
seeding on PostgreSQL; role-walkthrough defects; mobile messages send button;
admin colours after sign-out; My Services cafeteria daily rate; performance
scoring and KPI change guard after the arithmetic-library upgrade.

## Database migrations

All additive; none drops or rewrites data.

| Migration | Change |
| --- | --- |
| `2026_10_23_221443_register_core_access_permission` | adds the `core.access` permission |
| `2026_10_25_100000_add_positions_organization_foreign_key` | foreign key `positions.organization_id` (after an orphan audit) |
| `2026_10_26_100000_create_audit_log_seals_table` | new table for daily audit-log seals |
| `2026_10_26_100100_add_assignment_pointer_and_card_lineage_foreign_keys` | foreign keys on `employees.current_assignment_id` and `id_cards.previous_card_id` |

The foreign-key migrations fail if orphaned rows exist; run
`backbone:audit-integrity` first and repair through the application.

## API changes

- Additive v1 endpoints and fields as listed under Features.
- **Authorization behaviour (security exception within v1):** `POST /api/v1/cards/verify` and `POST /api/v1/services/{serviceType}/authorize` called by a person without `provider_code` now answer 422 `provider_required` instead of matching any provider; a body `service_type` that differs from the path is refused. Registered applications are unaffected (bound to one provider). Decided in ADR 0003; under [api-lifecycle.md](../governance/api-lifecycle.md) this class of change is breaking and was accepted as a security fix.

## Event changes

Five new event types (above); no published type or payload key changed or removed.

## Security changes

- Organization-level roles without a scope record reach only their own organization, or nothing (ADR 0003). Run `php artisan access:scope-gaps` before deploying and give the listed accounts a scope or a global role.
- Daily keyed, chained audit-log seals; legacy card-id QR refused by default.
- Empty organization sets fail closed on NFC, unit and delegation paths.

## Dependency changes

Laravel 12 → 13.38, laravel-permission 6 → 8.3, Laravel Excel 3.1 → 4.0
(PhpSpreadsheet 5.10), Pest 3 → 5, Inertia 3, Vite 8.3.5, TypeScript 7; PHP
floor 8.3; the lock installs on PHP 8.3–8.5. `composer audit` and `npm audit`:
no advisories. The previous production lock could not install on PHP 8.5 and
carried two high npm advisories (follow-redirects, Vite ≤ 6.4.3).

## Known issues

- Backups are not configured on the live host; the backup page reports NOT_CONFIGURED (TD-19).
- Go-live gates G1–G6 are open: failover and restore drills, penetration test, UAT, governance roles, retention approvals.
- Some module suites fail only on MySQL/MariaDB (TD-6).
- Partner tokens expire 8 hours after issue (TD-16).

## Deployment steps

`.env` with `APP_ENV=production`, `LOG_LEVEL=warning`; `composer install --no-dev --optimize-autoloader`;
`npm ci && npm run build` (Node 22.12+ or 24); database backup; `php artisan migrate --force`;
`php artisan db:seed --class=RoleSeeder --force`; `config:cache`, `route:cache`, `view:cache`;
`php artisan audit:seal`; scheduler cron and a queue worker; `production:readiness`.

## Rollback notes

The schema changes are additive, so the previous build runs on the new schema.
But the previous build's Composer lock does not install on PHP 8.5: on a PHP
8.5 host (erkihun.et) a rollback to `116792f7` fails at `composer install`.
Roll back there only by switching the site to PHP 8.4 first, or fix forward.
Restore the database backup only if a migration itself failed halfway.

## Gates confirmed

Full SQLite suite 4,583 passed (one file run separately and passing); MariaDB
run of the security, go-live, infrastructure and authentication suites 387
passed; `composer audit` and `npm audit` clean (24 October 2026, release
author). Not run before this release: the full suite on MySQL, static
analysis (TD-12), a staging rehearsal. No Release Manager is named yet (G5),
so the release gate sign-off is not recorded.
