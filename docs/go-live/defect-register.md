# Core defect and known-issue register

Opened 25 October 2026 (Core Stage 7). Every defect found by the Golden Path
re-audit of the code is listed with what was done about it. Owners are roles;
no person is named until the governance roles are filled (gate G5). Nothing
here is a UAT or penetration-test result: those have not taken place.

Severity: CRITICAL / HIGH / MEDIUM / LOW. A go-live is blocked by any open
defect that can corrupt data, expose data across organizations, authorize the
wrong identity or card, or cannot be recovered.

## Defects

| ID | Scenario | Severity | Module | Description | Expected | Actual (before fix) | Owner | Status | Fix | Retest |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| D-01 | HR officer without "view PII" saves an employee | HIGH | Employees | The form showed a blank national ID and saving erased it | Stored value kept | National ID and its uniqueness hash erased | Technical Custodian | Fixed | 06a7de34 | `GoldenPathStageOneFixesTest` |
| D-02 | Staff member acts on their own transfer or vacancy application | HIGH | Transfers, Vacancies | The applicant could screen, select, approve and complete their own application, outside their scope | Applicant refused every decision | Allowed (Super Admin too) | Technical Custodian | Fixed | 06a7de34 | `GoldenPathStageOneFixesTest` |
| D-03 | Public ID checker scans an issued, never-activated card | HIGH | ID cards | Shown as active; holder details reachable after consent | Not checkable | "active" | Technical Custodian | Fixed | 06a7de34 | `PublicIdCheckerTest` |
| D-04 | Scoped partner application verifies a card for a service | HIGH | API / services | Application organization bounds ignored on card-presenting routes | `outside_organization_scope` | Served like a city-wide application | Integration/API Owner | Fixed | 06a7de34 | `GoldenPathStageOneFixesTest` |
| D-05 | Hidden `position_title` on employee registration | MEDIUM | Employees, Positions | Created positions without `positions.create`, code rules or audit | Permission and Core action | Created silently | Technical Custodian | Fixed | 06a7de34 | full suite |
| D-06 | Separated employee transferred | MEDIUM | Assignments | Re-seated a separated employee | Refused | New active placement | Technical Custodian | Fixed | 06a7de34 | `GoldenPathStageOneFixesTest` |
| D-07 | Position deactivated, ended or moved while held | MEDIUM | Positions | Holders stranded; organization could be blanked (city-wide) | Refused / filled from unit | Allowed | Technical Custodian | Fixed | 06a7de34 | `GoldenPathStageOneFixesTest` |
| D-08 | Vacancy application for any employee | HIGH | Vacancies | Any submitter could file for any employee | Self or in scope | Allowed | Technical Custodian | Fixed | 0993b976 | `VacancyApplicationTest` |
| D-09 | Seat counts after a separation | MEDIUM | API, positions | A separated holder still counted as occupying the seat | Vacant | Occupied | Technical Custodian | Fixed | 6e923471 | full suite |
| D-10 | Vacancy transfer to an unusable position or of a separated employee | MEDIUM | Vacancies | No destination or placement checks | Refused | Allowed | Technical Custodian | Fixed | 6e923471 | `tests/Feature/Vacancy` |
| D-11 | Job description stamp on ended placements | LOW | Organization design | Overwrote closed placements, no events | Active placements only, events | Bulk overwrite | Technical Custodian | Fixed | 6e923471 | `BackboneStageTwoTest` |
| D-12 | Revoked partner application switched back on | MEDIUM | API management | Revocation reversible, tokens kept | Final, tokens deleted | Reversible | Integration/API Owner | Fixed | 8f1fd78b | `ApiManagementTest` |
| D-13 | ID card and position lists | LOW | Performance | Two extra queries per row | Fixed count | N+1 | Technical Custodian | Fixed | cab107c5 | `QueryBudgetTest` |

## Known issues (open, non-blocking)

| ID | Issue | Severity | Workaround | Owner | Target |
| --- | --- | --- | --- | --- | --- |
| K-01 | `DesignImportTest` can fail in a parallel test run (passes alone) | LOW | rerun alone | Technical Custodian | next test-infrastructure work |
| K-02 | Some tests fail only on MySQL/MariaDB (see technical-debt TD-6) | MEDIUM | live host runs MySQL: run the affected suites on MariaDB before each release | Technical Custodian | before go-live |
| K-03 | About 190 service-module files still read Golden Path models directly (frozen by `ServiceModuleCoreDependencyTest`) | LOW | the ratchet stops growth | Enterprise Architect | module by module |
| K-04 | No web screen for break-glass elevation (`access:elevate` is command-line) | LOW | operations run the command | Information Security Lead | when requested |

## Go-live blockers that are not defects

Gates G1, G2, G4, G5 and G6 (backups and failover drill, validated recovery
targets, penetration test and UAT sign-off, named governance roles, approved
retention) are open: see [go-live-gates.md](go-live-gates.md). Decision while
they are open: **NO-GO**.
