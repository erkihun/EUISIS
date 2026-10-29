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
| D-14 | A draft hierarchy version is published | CRITICAL | Hierarchy | A new draft started empty; publishing it replaced the live structure, so every scoped administrator lost their organizations | Draft starts as a copy of the live tree; publishing never drops an active organization | Live tree replaced by the draft's few edges | Technical Custodian | Fixed | f2f2ce6c | `HierarchyVersionDraftSafetyTest` |
| D-15 | Any date-only field is shown or printed (birth date on the ID card) | HIGH | All modules, ID cards | Date-only casts serialized as UTC midnight of the previous day (the app runs in Africa/Addis_Ababa) | The stored date | One day early (12 Apr printed as 11 Apr) | Technical Custodian | Fixed | 87b3d819 | full suite, browser E2E 2026-10-30 |
| D-16 | Any Ethiopian date shown or printed | HIGH | Calendar, ID cards | PHP and JS converters put the leap day on Y mod 4 = 0: one year in four was a day off (9,628 days 1930–2035) | 12 Sept 2023 = Meskerem 1, 2016 | Sept 11 | Technical Custodian | Fixed | 87b3d819 | `EthiopianCalendarServiceTest`, day-by-day check |
| D-17 | A card request already approved, rejected or cancelled is "verified" | HIGH | ID cards | Verify had no state check and reopened closed requests; a Super Admin bypassed state checks entirely | Refused | Reopened | Technical Custodian | Fixed | d7526f55, 87b3d819 | `CardRequestStateGuardTest` |
| D-18 | Card officer opens a card request | MEDIUM | ID cards | The page received each actor's full user record (email, phone, login and 2FA data) and the employee's full record | Names, identity and placement only | Full records | Technical Custodian | Fixed | d7526f55 | browser E2E |
| D-19 | HR clerk saves a new employee | MEDIUM | Employees | The unsaved-changes guard challenged the form's own save; Cancel silently dropped it | Save goes through | "Leave without saving?" on every save | Technical Custodian | Fixed | d7526f55 | browser E2E |
| D-20 | Any form with an auto-generated code | LOW | Code rules | A superseded preview (axios CanceledError) showed "Preview unavailable" and could clear the preview | Latest preview shown | False error | Technical Custodian | Fixed | d7526f55 | browser E2E |
| D-21 | Organization form with a bare website domain | LOW | Organizations | Browser URL check blocked the whole form | Accepted, stored with https:// | Form would not submit | Technical Custodian | Fixed | d7526f55 | browser E2E |

## Known issues (open, non-blocking)

| ID | Issue | Severity | Workaround | Owner | Target |
| --- | --- | --- | --- | --- | --- |
| K-01 | `DesignImportTest` can fail in a parallel test run (passes alone) | LOW | rerun alone | Technical Custodian | next test-infrastructure work |
| K-02 | Some tests fail only on MySQL/MariaDB (see technical-debt TD-6) | MEDIUM | live host runs MySQL: run the affected suites on MariaDB before each release | Technical Custodian | before go-live |
| K-03 | About 190 service-module files still read Golden Path models directly (frozen by `ServiceModuleCoreDependencyTest`) | LOW | the ratchet stops growth | Enterprise Architect | module by module |
| K-04 | No web screen for break-glass elevation (`access:elevate` is command-line) | LOW | operations run the command | Information Security Lead | when requested |
| K-05 | The breached-password check fails closed for privileged accounts when the lookup service is unreachable (local PHP had no CA bundle) | MEDIUM | live host: confirm `curl.cainfo`/`openssl.cafile` point to a CA bundle, or switch the check off only for an air-gapped install (System Settings) | Technical Custodian | before go-live |
| K-06 | The Grade Levels list is empty on the development database; a position grade must come from it | MEDIUM | load the official civil-service grade scale before positions are created | HR data owner | before data load |
| K-07 | MFA was not enforced for a new Super Admin on the development database | MEDIUM | confirm the MFA requirement for privileged roles in System Settings on the live host | Information Security Lead | before go-live |

## Go-live blockers that are not defects

Gates G1, G2, G4, G5 and G6 (backups and failover drill, validated recovery
targets, penetration test and UAT sign-off, named governance roles, approved
retention) are open: see [go-live-gates.md](go-live-gates.md). Decision while
they are open: **NO-GO**.
