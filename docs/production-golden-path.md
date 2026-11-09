# Production golden path

How Addis One is actually built, and the paths new work must follow. This is a
map of the real repository, not a target architecture. Related:
[risk register](production-risk-register.md) ·
[load balancing](load-balancing.md) · [database scale](database-scale.md) ·
[production runbook](production-runbook.md) · [go-live checklist](go-live-checklist.md).

## Stack

| Layer | Technology |
| --- | --- |
| Server | PHP 8.3+ (8.4 on the live server), Laravel 13; Node 24 LTS to build assets |
| UI | Inertia 2 + React 18 + TypeScript 5, built by Vite 6 |
| Database | PostgreSQL (production engine, decision D-5); SQLite only for fast local tests |
| Authorization | spatie/laravel-permission 6.21 + `OrganizationScopeService` (organization scope) |
| Documents | barryvdh/laravel-dompdf (PDF), maatwebsite/excel (XLSX/CSV) |
| Files | Laravel filesystem; `local` (private) and `public` disks, switchable to S3-compatible storage by environment |
| Backups | pgBackRest via `scripts/backup` and `deploy/backup` (PostgreSQL only) |

## Request path (one module end to end)

```
routes/<module>.php         route + middleware (auth, verified, mfa, force.password, admin.access)
  → Controller              thin: authorize, validate (FormRequest), call a service, render Inertia
    → Policy / AccessService     permission + OrganizationScopeService (+ case role for grievance/discipline)
    → Service / Action      business rules, DB::transaction + row locks, audit via WriteAuditLogAction
      → Eloquent models     UUIDv7 primary keys (HasUuidPrimaryKey)
  → resources/js/Pages/<Module>/*.tsx   React page; i18n in resources/js/i18n/{en,am}
```

Route files: `web.php` (core admin), `api.php` (partner API, Sanctum + `api.scope`),
`grievances.php`, `discipline.php`, `performance.php`, `assessments.php`,
`field-work.php`, `court-cases.php`, `auth.php`, `platform.php` (shared lookups),
`health.php` (load-balancer probes, outside the web group).

## Current production modules

Organizations, units, unit types, hierarchy versions and relationships ·
Employees and assignments · Positions, establishments, grades, occupations ·
Code rules · Employee ID cards, print batches, QR verification and the public ID
checker · Employee QR public feedback, suggestions and complaints · Transfers
(announcements, applications, approvals) and vacancies · Cafeteria (policies,
entitlements, scans, settlements, provider portal) · Daily activity · Field work ·
Employee grievance · Employee discipline · Administrative tribunal integration ·
EPMS / performance · Assessments and oversight · Users, roles, permissions, MFA ·
System settings, notifications, audit, reports, backup health, My Portal.

## Feature-flagged modules

| Module | Status | Flag |
| --- | --- | --- |
| **Transport Management** | **Live** (docs/transport-provider-module.md) | `features.transport=true` by default. `FEATURE_TRANSPORT=false` switches it off again: routes answer 404, navigation and command palette hide it and providers are not treated as offering transport. Code, tables and data are kept either way. |
| Court Cases | Planned placeholder | `features.court_cases=false` (default). |

Turn a disabled module on only by setting its flag after it is completed and
approved — never as a hotfix.

## Rules for new code (the golden path)

1. **Authorize with scope, always.** Every list, report and export over employee-owned
   data goes through `OrganizationScopeService` (`applyOrganizationScope`,
   `employeeIdsInScope`, `canAccessEmployee`). An empty scope means *nothing* — check
   `isUnrestricted()`, never `isNotEmpty()`.
2. **Never ship a population to the browser.** No `Employee::…->get()` for a dropdown.
   Use the scoped search endpoint `GET /lookup/employees` with the debounced
   `SearchPicker` component; send unit/position options for one organization at a time.
3. **Paginate in the database; aggregate in SQL.** Lists paginate; dashboards and
   summaries use `COUNT/SUM … GROUP BY`/conditional aggregation; never
   `->get()->sum()` over a period. Walk large sets with `chunkById`/keyset, never OFFSET.
4. **Mind the bind limit.** Never `whereIn('x', $idsFromAWholeScope)` — use a subquery.
   PostgreSQL allows 65,535 bound parameters.
5. **Integrity in the database.** A one-per-X rule needs a (partial) unique index, a
   row lock and a re-check of state *inside* the transaction. Lock in a consistent
   order (e.g. settlement → transaction; position entry → position → application →
   employee → assignment).
6. **Heavy work is queued.** Anything proportional to the workforce runs as a job on the
   `long` queue in resumable batches; notifications are `ShouldQueue` on
   `notifications`. Job `$timeout` must stay below the connection `retry_after` (960s).
7. **Files go through the disk.** Read/hash/download with `Storage::disk(...)`
   (`StoredFileChecksum`, `Storage::download`) — never `->path()` or a node-local
   absolute path. Public-disk files take their extension from the sniffed content
   (`PublicImageExtension`).
8. **Scheduled tasks** are `->withoutOverlapping()->onOneServer()` and idempotent.
9. **Migrations on large tables** are additive; indexes are built `CONCURRENTLY`
   (`$withinTransaction = false`); a new unique constraint first checks for existing
   violations and stops (NEEDS_DATA_REVIEW) rather than altering data.
10. **Tests** run on SQLite for speed and on PostgreSQL
    (`DB_TEST_DATABASE=<db>_test vendor/bin/pest -c phpunit.pgsql.xml.dist`) for any
    SQL that is not plain Eloquent.

## Shared services to reuse

| Need | Use |
| --- | --- |
| Organization scope | `App\Services\OrganizationScope\OrganizationScopeService` |
| Official references / numbers | `CodeGeneratorService` via `GenerateCodeAction` (row-locked sequences) |
| Working days / statutory deadlines | `WorkingDayCalculator`, `StatutoryDeadlineService`, `Regulation201` |
| Ethiopian calendar / localized dates | `App\Services\Calendar\*` |
| Audit | `WriteAuditLogAction` (redacts credentials at write; the viewer masks PII) |
| Notifications | module notifiers + queued `Notification` classes |
| Feature flags | `App\Support\Features`, `config/features.php` |
| Health | `/health/live`, `/health/ready` (`HealthController`); `/up` remains |
| Readiness check | `php artisan production:readiness` (`--strict` for go-live) |
