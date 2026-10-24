# Core workspace

Core holds the master records every service relies on: organizations and their units, positions, employees and their assignments, identity cards, and the governance around them (accounts, roles, API clients, integration events, code rules, audit). The backbone it sits on is described in [master-backbone.md](master-backbone.md).

Stage 1 gives Core its own entry point, navigation and dashboard inside the existing application. Nothing is extracted or moved. Every page Core links to is the page the full application already serves, with the same permission and the same organization scope.

## How it works

| Piece | Where | What it does |
| --- | --- | --- |
| Permission `core.access` | `database/seeders/data/core-permissions.php`, migration `register_core_access_permission` | Opens the workspace. Granted to Super Admin, System Admin, City Admin and Public Service Bureau Admin. It grants no record access of its own. |
| Entry `GET /core` | `CoreDashboardController` behind `auth`, `verified`, `mfa`, `force.password`, `admin.access`, `can:core.access` | Shows the Core dashboard and, through `EnterCoreWorkspace`, sets the session flag. |
| Entry `GET /core/login` | `CoreWorkspaceController::login` | Core's own sign-in screen: the sign-in page titled for Core, without self-registration, posting to the one login route (shared throttling, MFA and session). Afterwards the person lands in `/core`; a signed-in person goes straight there (ADR 0002). |
| Exit `POST /core/exit` | `CoreWorkspaceController::exit` | Clears the flag and returns to the full dashboard. Works without `core.access`, so a person whose access was withdrawn can always leave. |
| Session flag | `App\Services\Core\CoreWorkspace` | Active only while the flag is set and the person still holds `core.access`. Signing out ends it. |
| Navigation | `App\Services\Core\CoreNavigation` | The Core sidebar: master records (organization, change requests, positions, employees, identity cards) and governance (access, integration, rules and audit). Each entry is shown only when the person may open its page. |
| Shared prop `workspace` | `HandleInertiaRequests` | `{ name: 'core', nav }` while the workspace is active, `null` everywhere else. `AppSidebar` draws the Core navigation when it is set. |
| Dashboard figures | `App\Services\Core\CoreDashboardMetrics` | Counts of master records and of the ones needing attention. |

The full application is unchanged for everyone outside Core. People who hold `core.access` see one extra entry, Core, under Administration.

## Dashboard figures

Each figure is a single indexed count, computed once per organization scope and cached for five minutes.

| Domain | Figures | Shown with |
| --- | --- | --- |
| Organization structure | active organizations, active units, published hierarchy version | `organizations.view`, `organization-units.viewAny`, `hierarchy-versions.viewAny` |
| Positions | positions in use, retired positions, positions with and without a holder | `positions.viewAny` |
| Employees and assignments | active employees, current assignments, active employees without an assignment | `employees.view` |
| Identity cards | cards in use, cards expiring within 30 days, open card requests, suspended cards, revoked cards | `cards.view`, `id-cards.viewAny` |
| Governance | active accounts, connected applications, events waiting, audit entries in 24 hours | `users.viewAny`, `api_management.view`, `integrations.view`, `audit.view` |
| Data quality | findings of the Golden Path integrity checks (`BackboneIntegrityChecks`, the same as `backbone:audit-integrity`) | `audit.view`, citywide only |

Below the figures, audit readers see the latest master-data changes (event, person, time; never values), read from the audit log inside their scope.

A person scoped to some organizations sees counts for those organizations only, through `OrganizationScopeService`. Employee-owned records follow the employee's current assignment. Figures that only make sense citywide are left out for them: unplaced employees, connected applications, events waiting and audit volume.

## Core-only pages (Stage 2)

| Page | Route | Shown with | What it does |
| --- | --- | --- | --- |
| Assignments | `GET /core/assignments` | `employees.view` | Current placements (or all, with history) inside the viewer's scope; search by employee number or name, filter by organization code and status; 25 per page. Read-only: placements change through transfers and employment status (ADR 0002). |
| Integration Center | `GET /core/integration` | `api_management.view` or `integrations.view` | Partner applications (name, code, status, organization bound, scope and token counts, next token expiry, last use; never a secret or token) and the event pipeline figures. |
| Audit Center | `GET /core/audit` | `audit.view` | Fourteen ready-made views (organization, structure, position, employee, placement, card, numbering, account, role, permission, two-step sign-in, password, integration, application/system), counted over 30 days in scope, each opening the audit log filtered. The audit log gained `request_id` and `principal` (person or application/system) filters. |
| Settings | `GET /core/settings` | `system-settings.view` and each screen's own permission | The settings Core owns (general, language and calendar, appearance, security, ID cards, card templates, numbering rules, API), each opening its existing screen. |

The Core sidebar is grouped as Organization Structure, Change Requests, Workforce Structure (positions, status, establishments, grades, occupations, professions), Employee Master (employees, assignments, import, corrections), Unified Identity (cards, requests, reprint queue, print batches, templates, NFC credentials, card verification), Access Control, Integration, Rules & Audit, and Settings.

### Page availability

| Golden Path area | State |
| --- | --- |
| Organizations, types, units, unit types, hierarchy versions, relationships, change requests | DUAL AVAILABLE: the same pages in both shells |
| Positions, status, establishments, grades, occupations, professions | DUAL AVAILABLE |
| Employees, import, corrections | DUAL AVAILABLE |
| ID cards, requests, print batches, reprint queue, templates, NFC credentials | DUAL AVAILABLE |
| Users, roles, permissions, audit log, code rules, API management, event integration | DUAL AVAILABLE |
| Assignments list, Integration Center, Audit Center, Settings hub, Core dashboard | CORE PRIMARY (Core only) |

Nothing is LEGACY DEPRECATED: both shells call the same controllers and actions, so there is one set of business rules.

## Tests

- `tests/Feature/Core/CoreWorkspaceTest.php`: access control, the session flag, permission-filtered navigation, other pages unchanged with and without the flag, withdrawal of access, the entry address, role grants and the migration.
- `tests/Feature/Core/CoreDashboardTest.php`: counts against real records, scoped counts, permission-filtered figures, the rendered page, cache separation between scopes.
- `tests/Feature/Core/CoreWorkspacePagesTest.php`: each Core page refused without its permission, assignments scoped with search and history, no secret on the Integration Center, scoped audit views and the new audit filters, permission-filtered settings and navigation.
- `tests/Feature/GoldenPath/GoldenPathTest.php` (test 83): organization → unit → position → employee → card through the admin routes, then the same chain in the Core workspace and through the Core read contracts.
- `tests/Unit/BackboneBoundaryTest.php` treats `app/Services/Core` and `app/Http/Controllers/Core` as backbone code: Core never imports a service-module class.

## Rollback

Remove the three routes, the middleware alias, the `workspace` shared prop and the sidebar branch. The permission row is harmless if left in place; its migration's `down()` removes it.

## Next stages

1. Hierarchy publish with an impact review (organizations, units, positions, assignments and user scopes affected) before a version goes live.
2. A staff route for suspending a card. Start and End assignment are done for employment ending and resuming ([ADR 0002](adr/0002-core-write-authority-assignment-ending-and-sign-in.md)); backbone Stages 1 to 7 are done ([backbone-refactor-migration.md](backbone-refactor-migration.md), section 9); retiring the duplicate masters waits for live evidence.
3. Move the service modules onto backbone contracts or the API, one per release.
4. Make Core authoritative, then remove the master-record groups from the full sidebar last.
