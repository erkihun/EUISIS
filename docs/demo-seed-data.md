# Demo seed data (development / QA / UAT)

A deterministic, synthetic dataset for development, QA, UAT, UI and workflow testing, integration testing and demonstrations. **It is not production master data.**

```bash
php artisan db:seed --class=DemoDataSeeder   # seed (safe to re-run)
php artisan demo-data:validate               # read-only checks; exit 1 on any failure
```

## Safety

- **Refused in production.** Every demo seeder throws `Demo data seeding is disabled in production.` when `APP_ENV=production`, including a sub-seeder run on its own with `--class`. There is no override.
- **Never part of `DatabaseSeeder`.** `php artisan db:seed` seeds reference/system data as before; the demo dataset is always an explicit command.
- **Never destroys data.** No truncate, no delete, no sequence reset. A re-run finds its own records by natural key and adds nothing. There is no reset command: on a development database use `php artisan migrate:fresh --seed` and seed the demo again.
- **No external side effects.** During seeding the SMS kill switch is off (`security.external_usage.sms.enabled`), mail goes to the `array` mailer and queued jobs run synchronously, so nothing is sent later by a worker.
- **Synthetic identities.** National IDs are valid-format 16-digit values beginning with ten zeros; emails use the reserved `example.test` domain; no phone numbers. Names are realistic but invented.
- **Existing records.** Reference records (organization types, unit types, occupations, code rules, grievance defaults) are reused; missing ones are created, existing ones are never overwritten. `RoleSeeder` / `RolePermissionSeeder` run as they do for `db:seed`: in the default `sync` mode system roles are reset to the role matrix (set `RBAC_DEFAULT_ROLE_SYNC=additive` to only add). Custom roles and users are never touched.

## How it is built

Everything goes through the application's own actions and services — the same code the pages use — so codes, audit entries and hidden invariants are real:

| Data | Through |
|---|---|
| Organization, unit, position codes; employee and card numbers | `GenerateCodeAction` / code rules (no DEMO prefix on generated codes) |
| Organizations, hierarchy | `CreateOrganizationAction`, `CreateHierarchyVersionAction`, `CreateOrganizationEdgeAction`, `PublishHierarchyVersionAction` |
| Units, functional relationship | `CreateOrganizationUnitAction`, `OrganizationUnitRelationshipService` |
| Positions, employees + assignments | `CreatePositionAction`, `RegisterEmployeeAction` (one active occupant per position, encrypted national ID) |
| Accounts, scopes | `CreateUserAction`; scopes as the scope page stores them, with audit |
| ID cards | `SubmitCardRequestAction` → `ApproveCardRequestAction` → `IdCardPrintSnapshotService` → `IssueCardAction` → `ActivateCardAction` |
| Providers, networks, cafeterias | `CreateCafeteriaProviderAction`; provider-portal accounts via `ProviderUserAccountService` |
| Access, assignments, policies | `CafeteriaAccessService`, `CafeteriaAssignmentService`, `CafeteriaPolicyWorkflowService` — each approved by a second person |
| Meals | `CafeteriaQrScanService` (policy resolution, entitlement ledger, pricing snapshot) |
| Leave | `CreateEmployeeCafeteriaExclusionAction` |
| Daily Activity, EPMS, Grievance | `DailyActivityService`; `PerformanceCycleService`, `StrategicPlanningService`; `GrievanceCommitteeService`, `GrievanceCaseService` |

Seeders: `database/seeders/DemoDataSeeder.php` (orchestrator) and `database/seeders/Demo/`. The catalog (names, amounts, scenarios) is `app/Support/Demo/DemoDataset.php`; the checks are `app/Support/Demo/DemoDataValidator.php`.

**Tagging.** Records whose code the code rules generate carry `metadata.demo_dataset = euisis-demo-v1` and `metadata.demo_key` (e.g. `ORG-1`, `E-2-3`, `P-HR`). Providers, networks and cafeterias use `DEMO-` codes; accounts use `demo.*@example.test`. Demo rows do **not** set `is_demo`: `DatabaseSeeder` purges `is_demo` rows it owns on every run, and this dataset must survive a normal `db:seed`.

**Dates.** The day of the first run is stored on the root organization (`metadata.seeded_on`). Structure, assignments, access and v1 policies start at `epoch` = the first of the month one year earlier. Historical meals are the most recent weekdays before `seeded_on` on which the cafeteria is open and that are not public holidays. A later run reuses these dates, so it never adds new "recent" rows. All dates are Gregorian; Ethiopian dates are display-only.

## Organizations

| Key | Name | Type | Test purpose |
|---|---|---|---|
| ROOT | DEMO City Administration | City Administration | Hierarchy root (support record, not counted as one of the five) |
| ORG-1 | DEMO Civic Services Bureau | Bureau | Basic HR + cafeteria happy path |
| ORG-2 | DEMO Urban Works Authority | Authority | Cross-location (main / branch) cafeteria use |
| ORG-3 | DEMO Public Health Commission | Commission | Different working-day and advance-use policy |
| ORG-4 | DEMO Planning and Development Bureau | Bureau | Subtree scope, extra units, management |
| ORG-5 | DEMO Records and Archives Agency (child of ORG-4) | Agency | Policy history; Daily Activity (ORG-1), EPMS and Grievance data |

All names are bilingual (English / Amharic). Hierarchy: ROOT → ORG-1…ORG-4, and ORG-4 → ORG-5.

| | Units | Positions (vacant) | Employees | Accessible cafeterias | Provider | Daily subsidy (demo) |
|---|---:|---:|---:|---:|---|---|
| ORG-1 | 6 | 7 (1) | 6 | 5 | A | 100.00 |
| ORG-2 | 6 | 7 (1) | 6 | 7 | A | 120.00 + 10.00 employee |
| ORG-3 | 6 | 7 (1) | 6 | 5 | B | 140.00 |
| ORG-4 | 8 | 10 (2) | 8 | 5 | B | 160.00 + 20.00 employee |
| ORG-5 | 6 | 7 (1) | 6 | 5 | C | 170.00 (v1) → 180.00 (v2) |
| **Total** | **32** | **38** | **32** | 25 locations | 3 | |

**Units** (each organization): Office of the Head → Human Resource, Finance, Information Technology, Planning and Performance, and Service Delivery Directorates. ORG-4 adds a Recruitment Team (under HR) and a Customer Service Team (under Service Delivery). Structural parent = `parent_unit_id`; in addition Service Delivery has a **functional reporting** relationship to Planning and Performance (unit → unit, so position-code ownership is unchanged).

**Positions:** Head of Organization (manager), Human Resource Officer, Finance Officer, ICT Officer, Planning and Performance Officer, Service Delivery Officer, and a vacant Records Clerk. ORG-4 adds a Human Resource Director (manager), a Recruitment Officer and a vacant Customer Service Officer. Occupations are OccupationSeeder's reference ones; grade levels are set only if the project has some (none are invented).

**Employees:** `E-{org}-{n}` — `E-1-1` is ORG-1's head, `E-1-6` its Service Delivery Officer, and so on. Every employee has exactly one current active assignment (organization → unit → position).

## Accounts

Password: `DEMO_USER_PASSWORD` from the local environment. On a developer machine or in tests, if it is not set, the documented development fallback `password` applies. Outside `local`/`testing` the variable is required. Accounts are created once; a re-run never resets a changed password. Accounts in `MFA_REQUIRED_ROLES` (by default City Admin and Cafeteria Admin) must enrol MFA at first sign-in outside tests.

No Super Admin is created: use the one from `DatabaseSeeder` / `UserSeeder` (`super.admin@demo.local`).

| Email | Role(s) | Scope | Use |
|---|---|---|---|
| demo.city.admin@example.test | City Admin | citywide | Performs the seeder's actions (maker); city-wide admin |
| demo.cafeteria.admin@example.test | Cafeteria Admin | subtree of ROOT | Second approver (checker) for access, assignments, policies |
| demo.idcard.approver@example.test | ID Card Approver | subtree of ROOT | Approves card requests |
| demo.org1.admin@example.test | Organizational Admin | self: ORG-1 | Organization scope |
| demo.org4.subtree.admin@example.test | Organizational Admin | subtree: ORG-4 | Sees ORG-4 and ORG-5, not ORG-1 |
| demo.org1.hr@example.test | HR Officer | self: ORG-1 | Authorized for ORG-1, refused for ORG-2 |
| demo.idcard.officer@example.test | ID Card Officer | self: ORG-1, ORG-2 | Card work in two organizations |
| demo.org1.manager@example.test | Employee, Daily Activity Reviewer | self: ORG-1 | Portal account of `E-1-1` (manager); reviews Daily Activity |
| demo.org1.employee@example.test | Employee | — | Portal account of `E-1-6` |
| demo.grievance.admin@example.test | Grievance Administrator | self: ORG-5 | Committees, routes |
| demo.grievance.officer@example.test | Grievance Officer | self: ORG-5 | Grievance intake |
| demo.org5.chair / .writer / .member@example.test | Employee + committee role | — | Portal accounts of `E-5-2`, `E-5-3`, `E-5-4` |
| demo.org5.employee@example.test | Employee | — | Portal account of `E-5-6` (complainant) |

Subtree scopes resolve through the **published** hierarchy. On a fresh database the demo hierarchy is published. If another version is already published, the seeder never replaces it: "DEMO Hierarchy v1" is left as a **draft** containing the current published structure plus the demo organizations, and `demo-data:validate` says so. Publish it (development/UAT only) to enable the demo subtree scopes.

**Provider portal** (`/provider/portal/login`, `provider_users`, same password):

| Email / username | Provider | Role |
|---|---|---|
| demo.provider.a.owner@example.test / demo.prv.a.owner | A | owner |
| demo.provider.a.manager@example.test / demo.prv.a.manager | A | manager |
| demo.provider.a.operator@example.test / demo.prv.a.operator | A | operator (scanner) |
| demo.provider.b.operator@example.test / demo.prv.b.operator | B | operator |

## Cafeterias

```
DEMO Catering Provider A (DEMO-PRV-A)
├── DEMO-NET-A1  Main · Branch 1–3 · Service Point      ← ORG-1 and ORG-2
└── DEMO-NET-A2  Main · Branch 1–3 · Service Point      ← ORG-2 (cross-location OFF)
DEMO Catering Provider B (DEMO-PRV-B)
├── DEMO-NET-B1  …                                       ← ORG-3
└── DEMO-NET-B2  …                                       ← ORG-4
DEMO Catering Provider C (DEMO-PRV-C)
└── DEMO-NET-C1  …                                       ← ORG-5
```

Cafeteria codes: `DEMO-{network}-{MAIN|BR1|BR2|BR3|SP1}`, open 07:00–19:00. Branches and the service point sit under their network's main cafeteria.

| Organization | Access | Service assignment | Policy (synthetic demo terms — not official policy) |
|---|---|---|---|
| ORG-1 | A1, primary Main, cross-location ON | Provider A, network A1 | 100.00, Mon–Fri, advance use ON |
| ORG-2 | A1, primary **Branch 1**, cross-location ON; A2, primary Main, cross-location **OFF**, exception: Branch 1 allowed | Provider A, provider-wide | 120.00 + 10.00 = 130.00, **Mon–Sat**, advance use ON |
| ORG-3 | B1, primary Main, cross-location ON | Provider B, network B1 | 140.00, Mon–Fri, advance use **OFF**, extra scans employee-paid |
| ORG-4 | B2, primary Main, cross-location ON | Provider B, network B2 | 160.00 + 20.00 = 180.00, Mon–Fri, advance use ON, **at most 2 days ahead** |
| ORG-5 | C1, primary Main, cross-location ON | Provider C, network C1 | **v1** 170.00 from `epoch` (superseded) → **v2** 180.00 from `seeded_on` − 14 days (active) |

There is no global subsidy: each organization has its own policy. `provider_price = daily subsidy + employee contribution`, as the policy workflow enforces. Every access, assignment and policy is drafted by the DEMO City Admin and approved by the DEMO Cafeteria Admin (maker-checker). ORG-5's v2 was drafted with *Create New Version*; approving it ended v1 the day before and marked it superseded, so the two never overlap.

**Cross-location scenario.** `E-2-2` (ORG-2) eats at DEMO A1 Main Cafeteria, the main cafeteria ORG-1's staff use. The transaction records: employee organization and bill = ORG-2, policy = ORG-2's (120.00, not 100.00), service location = A1 Main, payee = Provider A.

**Historical meals** (27, all through the scan service; nonces are derived from employee and date):

| Employees | Where | Meals | Shows |
|---|---|---:|---|
| E-1-2, E-1-3, E-1-4 | A1 Main, A1 Branch 2 | 6 | Happy path |
| E-2-2 · E-2-3 · E-2-4 | A1 Main (cross-location) · A1 Branch 1 (primary) · A2 Branch 1 (exception) | 5 | Access rules |
| E-3-2, E-3-3 | B1 Main, B1 Branch 2 | 4 | ORG-3 policy |
| E-4-2, E-4-3 | B2 Main, B2 Service Point | 4 | ORG-4 policy |
| E-5-2, E-5-3 | C1 Main, C1 Branch 1 | 8 | 4 meals under v1 (170.00) and 4 under v2 (180.00): history keeps its snapshot |

**Leave.** `E-1-5` has an approved leave (cafeteria exclusion) for 14 days from `seeded_on`: a scan during that time is refused as "employee on leave".

## ID cards

Every employee has one card, created through the full lifecycle (card number from the code rule, random token, stable PII-free QR `…/id-checker/{public_card_uuid}`). 30 are **active**. Exceptions:

| Employee | Card |
|---|---|
| E-2-6 | Reported **lost** (`ReportLostOrDamagedCardAction`) |
| E-3-6 | **Expired** — no action expires a card early (cards lapse by date), so this example is dated in the past and audited as `card_expired` |
| E-4-6 | Active, **reprint required**: the English name was corrected after printing, and the employee observer flagged the card |

Printing stores the card artwork on the local disk, as in production.

## Optional modules

| Module | Status | Data |
|---|---|---|
| Daily Activity | Seeded | On the latest working day that backdating still allows: `E-1-6` approved (by the ORG-1 manager), `E-1-2` submitted, `E-1-3` draft. Skipped if the module is disabled or no working day is open |
| EPMS | Seeded (planning only) | ORG-5 cycle `DEMO-EPMS-{year}` in PLANNING; three draft strategic goals, 30 + 40 + 30 = **100%**, each fully allocated to units with one lead. `DEMO-SG-1` (30%) is shared: Planning 15% (lead), Service Delivery 10%, ICT 5%. **Not seeded:** plans, objectives, KPIs, targets (they come from the plan/cascade workflow), so the goals stay drafts |
| Grievance Management | Seeded | ORG-5 committee (chairperson `E-5-2`, writer `E-5-3`, member `E-5-4`), created by the Grievance Administrator and approved by the City Admin. Approved routes: ORG-5 → committee → ORG-4 Customer Service Team → ORG-4 HR Directorate. Category `DEMO-WORK-CONDITIONS`. Two cases by `E-5-6`: one **submitted** (waiting at intake), one accepted, routed and **under review** by the chairperson. **Not seeded:** an escalation-ready case (needs an SLA clock that has run out) and a decision (drafting, approval and issue workflow) |
| Court Cases | `SKIPPED_PLANNED_MODULE` | Placeholder only (see [court-cases.md](court-cases.md)) |

## Validation

`php artisan demo-data:validate` is read-only and checks: 5 demo organizations; per organization at least 5 units, positions, employees and accessible cafeterias, and an active policy today; one valid current assignment per employee (organization / unit / position consistent); no over-occupied position and at least one vacancy; unique employee numbers, organization codes and card numbers; cafeteria placement (provider, one main, parent in network); access and assignment validity; no overlapping binding policies; distinct subsidies; transaction snapshots (owner = employee's organization on the date, payee = the cafeteria's provider, policy governs that date and owner); the demo hierarchy version. It prints counts and exits 1 on any failure.

Tests: `tests/Feature/Demo/DemoDataSeederTest.php`.
