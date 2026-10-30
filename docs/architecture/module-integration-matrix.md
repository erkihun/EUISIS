# Module integration matrix

How each module uses the Core (the Golden Path backbone). "Reads" lists the
Core records a module needs; "Writes" must be "none" or a Core command.

Status on 25 October 2026 (Core migration Stage 3). The direct-import counts
are files that still `use` a Golden Path model; they are frozen by
`tests/Unit/ServiceModuleCoreDependencyTest.php` and may only go down. No
service module writes a Golden Path record at runtime: that is refused by
`tests/Unit/BackboneBoundaryTest.php` (only the development load-test seeder
is allowed).

## Migration status

| Status | Meaning |
| --- | --- |
| DIRECT_CORE_ACCESS | reads Core models directly (allowed inside one deployment, frozen by the ratchet) |
| ADAPTER_INTRODUCED | a module adapter over Core contracts exists; some reads still direct |
| CONTRACT_MIGRATED | the module's Core reads go through contracts |
| EVENT_SYNCED | the module reacts to Core events instead of observing Core models |
| MIGRATION_COMPLETE | no direct import left, writes only through Core commands |

| Module | Files importing Core models | Status | Contracts / adapters used | Core events consumed | Writes to Core |
| --- | --- | --- | --- | --- | --- |
| Cafeteria (`Modules/Cafeteria`, own public contracts: [Modules/README.md](../../Modules/README.md#public-contracts)) | see the ratchet | ADAPTER_INTRODUCED | `CredentialLookup`, `ServiceEligibility` (every scan); `CafeteriaEmployeeContext` over `AssignmentContextReader` (exclusion audit context); `EmployeeDirectory` (exclusion creation and selected ledger employee) | none (live verification per scan) | none |
| Transport (`Modules/Transport`, own public contracts: [Modules/README.md](../../Modules/README.md#public-contracts)) | 15 | ADAPTER_INTRODUCED, EVENT_SYNCED | `CredentialLookup`, `ServiceEligibility` (boarding), `EmployeeDirectory` (manual lookup) | `AssignmentChanged`, `EmployeeChanged` (eligibility re-evaluation) | none |
| Entitlements / service delivery | moved to `Modules/Entitlements`; Core imports remain in the ratchet | CONTRACT_MIGRATED for the service gate | `ServiceEligibility`, `CredentialLookup`; publishes `ServiceDeliveryRegistry` for owner writes | none | none |
| Communication | 12 | EVENT_SYNCED | none | `AssignmentChanged`, `EmployeeChanged` (membership sync) | none |
| Transfer | 15 | DIRECT_CORE_ACCESS (reads); writes through Core | `TransferAssignment` command | none | `TransferAssignment` only |
| Recruitment / vacancy | 4 | DIRECT_CORE_ACCESS (reads); writes through Core | `TransferAssignment`, `PositionCapacityService` | none | `TransferAssignment` only |
| EPMS / performance | 16 | DIRECT_CORE_ACCESS | none | none | none |
| Assessment | 13 | DIRECT_CORE_ACCESS | none | none | none |
| Daily work | 12 | DIRECT_CORE_ACCESS | none | none | none |
| Field work | 8 | DIRECT_CORE_ACCESS | none | none | none |
| Public / service feedback | 17 | DIRECT_CORE_ACCESS; registers its feedback-link adapter into Core | implements `EmployeeFeedbackLinks` | none | none |
| Reception / information desk (`Modules/Reception`) | 5 | DIRECT_CORE_ACCESS (scoped identity and organization/service directory reads) | organization-scope, audit, code-rule and work-calendar platform services | none | none |
| Grievance | 15 | DIRECT_CORE_ACCESS | none | none | none |
| Discipline / appeal | 3 | DIRECT_CORE_ACCESS | none | none | none |
| Organization design | 37 | DIRECT_CORE_ACCESS; job-description stamp through Core | `StampJobDescriptionOnCurrentAssignments` | none | that command only |
| Situation Room | 12 | DIRECT_CORE_ACCESS (read-only aggregates) | none | none | none |
| Intelligence / assistant | 2 | DIRECT_CORE_ACCESS (read-only, safe query services) | none | none | none; may never change master data |
| Provider portals / NFC | 7 | ADAPTER_INTRODUCED | `CredentialLookup`, `ServiceEligibility` | none | none |
| External systems | n/a | API only | `/api/v1` with scopes and organization bounds | change feed, webhooks | none (ADR 0002) |

Total: 208 files in service modules import a Golden Path model, by the ratchet baselines in
`tests/Unit/ServiceModuleCoreDependencyTest.php` on 30 October (the per-module counts above are
older and may differ; the test is authoritative). Transport's baseline went from 8 to 15 that day
without new coupling: its seven models' Core relations became visible once they left the shared
`App\Models` namespace, and the ratchet now scans `Modules/` as well as `app/`.

Ownership of every model and table is recorded in [`Modules/ownership.php`](../../Modules/ownership.php);
modules that have moved into `Modules/` and the order of the rest are in
[`Modules/MIGRATION.md`](../../Modules/MIGRATION.md) (ADR 0017).

The exclusion-create and ledger-filter employee lookups now use the scoped
`EmployeeDirectory` contract. Their existing URLs, permissions, cross-organization
denials and response fields are preserved. Ledger balances can be read by employee
UUID, while existing callers can still supply an Employee model. The ledger list
and exclusion resources still use employee relationships; removing these two
imports does **not** complete Cafeteria's physical separation.

## What each module owns

| Module | Owns |
| --- | --- |
| Cafeteria | meal transactions, scans, menus, policies, ledgers, settlements, exclusions |
| Transport | routes, stops, trips, vehicles, drivers, devices, boardings, invoices, settlements |
| Recruitment / vacancy | announcements, applications, selection |
| Transfer | applications, screening, approvals, transfer records |
| EPMS / performance | cycles, agreements, KPIs, evidence, appraisals, results |
| Assessment | cycles, forms, responses, results |
| Daily work | daily logs, items, evidence, reviews |
| Field work | requests, approvals, participants, locations |
| Public feedback | feedback, suggestions, complaints, routing, responses |
| Reception / information desk | desks, counters, visitor check-ins, queue tickets, appointments, referrals |
| Grievance | cases, stages, hearings, decisions, letters |
| Discipline / appeal | cases, charges, decisions, appeals |
| Communication | conversations, channels, announcements |
| Organization design | studies, proposals, manpower plans, imports |
| Situation Room | actions and their events |
| Intelligence / assistant | alerts, forecasts, knowledge, runs |
| Provider portals | provider users, terminals |

None of them keeps an employee, organization, position, assignment or card
identity table of its own; `*_snapshot` columns are evidence, not masters.

## Next migrations, lowest risk first

1. Cafeteria: move remaining list/resource relationship reads and reporting lookups behind Core contracts; exclusion creation and selected ledger employee lookups already use `EmployeeDirectory`.
2. Provider portals: scan lookups through `CredentialVerification`.
3. EPMS and assessment: current placement through `AssignmentContextReader`, organization subtree through `OrganizationHierarchy`.
4. Daily and field work: the same two contracts.
5. Feedback, grievance, discipline: employee references through `EmployeeDirectory`.
6. Transfer and recruitment last: their writes already go through `TransferAssignment`.

Each step lowers the module's number in the ratchet in the same change.

Core events available to every consumer are listed in the
[event catalog](../integration/event-catalog.md).
