# Court Cases (planned module)

Amharic: የፍርድ ቤት ጉዳዮች

Court Cases is a **planned standalone EUISIS module**. Only its boundary exists
today: a navigation entry, one permission, one protected route and a
placeholder page. The module itself will be designed in a later phase.

## What exists now

| Piece | Where |
|---|---|
| Route `GET /court-cases` → `court-cases.index` | `routes/court-cases.php` (staff middleware: `auth`, `verified`, `mfa`, `force.password`, `admin.access`) |
| Controller | `app/Http/Controllers/CourtCases/CourtCaseController.php`, which checks `court_cases.view` |
| Permission `court_cases.view` | `database/seeders/data/court-case-permissions.php`, registered by `2026_09_29_100000_register_court_case_permissions` |
| Permission category | `Court Cases` in `PermissionCatalog::CATEGORIES` |
| Sidebar | Its own group `courtCases` in `AppSidebar.tsx`, shown only with `court_cases.view` |
| Page | `resources/js/Pages/CourtCases/Index.tsx`: title, description and a "Planned Module" status |
| Strings | `resources/js/i18n/{en,am}/courtCases.ts`, `nav.groupCourtCases` / `nav.courtCases` |

Default roles get `court_cases.view` the same way as any catalog permission:
Super Admin and System Admin hold every permission, and City Admin and Public
Service Bureau Admin hold every permission not withheld from them. No other
role gets it by default.

**Not created**: no database tables (no `court_cases`, hearings, judges,
benches, appeals, orders, decisions or parties), models, services, policies,
workflow, or API/public routes. There is no sample or demo data. The only
permission is `court_cases.view`. Do not add `court_cases.create`, `.assign`,
`.decide`, `.appeal` or similar until the module is designed.

## Separate from Grievance Management

Court Cases and Grievance Management are separate business domains.

- The module lives in its own route file, controller namespace, page folder,
  permission group and sidebar group. It is not a section of Grievance
  Management.
- A grievance case is not a court case. A grievance is never converted into
  a court case automatically.
- If a grievance later leads to court proceedings, the link will be an
  explicit referral or reference recorded by a person. The grievance keeps
  its own record and outcome.
- The existing **Administrative Tribunal** register (`/tribunal-cases`,
  `grievances.tribunal`) belongs to Grievance Management and is unchanged.
  Its Amharic label is `የአስተዳደር ፍርድ ቤት ጉዳዮች`, so it cannot be
  confused with this module (`የፍርድ ቤት ጉዳዮች`).

## To be defined by the future design

The module design will define:

- court case intake
- parties
- case types
- court/tribunal
- case assignments
- hearings
- evidence
- filings
- deadlines
- orders
- judgments/decisions
- appeals
- correspondence
- documents
- notifications
- reporting
- confidentiality
- integrations

None of these are implemented.

## Possible future integration points

These are candidates for the design to consider. **No relationship to any of
them exists in code or schema.**

- Organizations
- Organization Units
- Employees
- Employee Assignments
- Positions
- Users / RBAC
- Documents
- Notifications
- Audit Logs
- Grievance Management, only where an official referral exists
- Administrative Tribunal integration, where applicable

## Tests

`tests/Feature/CourtCases/CourtCasesPlaceholderTest.php` covers access
control, the sidebar entry, EN/AM strings, and the absence of public, API and
grievance routes.
