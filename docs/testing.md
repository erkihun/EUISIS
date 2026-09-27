# Testing Strategy

## Goals

- Prove scope-based authorization is enforced.
- Prove structure and assignment history are preserved.
- Prove card workflow and verification rules are enforced securely.
- Prove sensitive actions create audit logs.

## Test Layers

- Feature tests for API endpoints, policies, web updates, and workflow rules
- Basic web access tests for scoped pages
- Action/service behavior verified through feature-level lifecycle tests using in-memory SQLite

## Core Coverage

- organization subtree authorization
- hierarchy version publishing
- hierarchy version CRUD permissions, validation, and archive rules
- hierarchy tree view/edit permissions and scoped relation mutation routes
- hierarchy relation add/update/remove audit coverage
- mojibake regression checks for hierarchy Amharic localization files
- transitive closure depth generation for published hierarchy versions
- employee registration and initial assignment creation
- duplicate-flag detection foundation
- employee transfer lifecycle
- transfer page access and permission checks
- transfer approval preserving employee UUID and employee number
- position CRUD permission checks and unique `job_position_code` validation
- organization parent-option scope filtering, search behavior, and child-creation validation
- service type CRUD permission checks, validation, and audit logging
- entitlement rule CRUD permission checks, validation, and audit logging
- code rule CRUD permission checks, preview, archive/restore conflict handling, and audit logging
- generated organization codes, employee numbers, job position codes, and ID card numbers
- dashboard permission, scope, PII, and bounded-list behavior
- stable employee UUID across transfer
- employee update audit logging
- card approval/print/issue ordering rules
- card replacement linkage and prior-card suspension
- secure QR token handling
- verification denial rules
- provider/service scope enforcement
- Sanctum token ability enforcement for provider APIs
- audit log creation for sensitive actions
- profile settings updates, safe profile photo upload, shared avatar props, and national ID privacy
- user create/edit validation for profile photo, national ID, phone number, gender, roles, blank passwords, self-deactivation, and last Super Admin protection

## How To Run

```bash
php artisan test
```

By default the suite runs against SQLite in memory through `phpunit.xml.dist`.

### On PostgreSQL (the production engine)

SQLite accepts things PostgreSQL rejects — comparing a uuid column with `''`,
`MAX()` over uuids, over-long values in a uuid column, NULL ordering — so run
the suite on PostgreSQL before every release (go-live condition C1).

```bash
# Once: an empty database used only by tests. Every run wipes it; never point
# this at the application's own database.
#   CREATE DATABASE aaemployeedb_test ENCODING 'UTF8';
DB_CONNECTION=pgsql DB_DATABASE=aaemployeedb_test php artisan test --parallel
```

Host, port, user and password come from `.env`; the variables above override
`phpunit.xml.dist` because it does not force them. Parallel runs create one
`<database>_test_<n>` database per worker, so the user needs `CREATEDB`.
A default PostgreSQL (`max_locks_per_transaction = 64`) runs out of shared
memory with many workers rebuilding the schema at once: use `--processes=4` or
fewer, or raise that setting.

### Coverage

Needs the PCOV extension. On Windows, keep it out of the PHP installation and
load it through an extra ini directory, which parallel workers inherit:

```bash
# conf.d/pcov.ini:
#   extension="C:/path/to/php_pcov.dll"   (PECL build matching PHP version, NTS/TS, vs16, x64)
#   pcov.enabled=1
#   pcov.directory="C:/…/EUISIS/app"
PHP_INI_SCAN_DIR="C:/path/to/conf.d" php artisan test --parallel --coverage-clover=coverage.xml
```

Baseline 2026-09-27 (SQLite, 2 470 tests): **71.9 % of executable lines in `app/`**.
Lowest-covered risk areas at that date: `MfaController` 5 %, organization scope
service 60 %, policies 52 %, cafeteria back-office transactions controller 36 %,
cafeteria module 57 %, transfers 45 %, grievances 50 %, transport 4 %.
Line coverage is not path coverage: a covered line may still hide untested
combinations of role, organization, date and state.

After the risk-first round (2026-09-27, 2 500 tests): **72.7 %** overall.
MFA controller 94 %, MFA middleware 93 %, cafeteria provider access 91 %,
print-batch policy 100 %, cafeteria transactions controller 66 %, sensitive-data
encryption command 81 %. The round found and fixed 7 defects (readiness
assessment PRA-29 … PRA-35). Still low and not yet addressed: transfers,
grievances, vacancies and transport (only if in the go-live scope); the
organization scope service stays at 66 % because `buildVersionTree()` is unused.

## Remaining Gaps

- full print-batch rendering and PDF validation
- settlement engine edge cases
- offline sync cryptographic signature tests
- browser-level acceptance coverage for all admin pages
- remaining database-to-UI coverage gaps for approval, API client, device binding, transport, cafeteria, consumer, and support modules
- high-contention concurrent generation stress coverage for `code_rules` still relies on transactional design review more than true parallel test execution
- PostgreSQL-only constraint tests for single current assignment / single current card rules
- deeper workflow branching tests for transfer rejection/cancellation permutations
- dashboard export and scheduled snapshot coverage once those features exist

## Recycle Bin

Recycle Bin coverage verifies protected access, soft-delete metadata, restore behavior, and record_deleted / record_restored audit events for supported configuration records.
# Cafeteria Scan Checks

- Provider operators must only receive assigned cafeteria providers in scan provider lists.
- Scan requests require `scan_nonce`; replayed fulfilled nonces must not create another transaction.
- `custom_amount` and requested subsidy amount inputs are rejected by backend validation.
- Remaining-week scans must persist consumed dates and the calendar response must mark those dates consumed.
- Today’s Scans must include employee identity fields and must not expose national ID, raw QR token, or QR hashes.
## Cafeteria Provider Portal Checks

```bash
php artisan route:list --path=cafeteria/portal
php artisan test tests/Feature/CafeteriaProviderPortalSeparationTest.php
php artisan test tests/Feature/CafeteriaProviderTransactionExportTest.php
npm run build
```

These cover the `/cafeteria/portal` route namespace, portal login render, auth requirement, provider-only admin redirect, portal Inertia context, and TypeScript/Vite compilation.

The transaction export test covers provider-scoped CSV, XLSX, and PDF output, payment claim summary output, permission denial, sensitive-field exclusion, and export audit logging.
