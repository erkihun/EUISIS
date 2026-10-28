# Golden Path prompt pack application

Date: 25 October 2026. Source: `EUISIS_Golden_Path_Stage_1_to_Stage_8_Prompts.txt`.

This records application of the supplied eight-stage requirements to the current
repository. The document's statements that previous stages are complete, the Core
is physically separated, or the platform is live are prerequisites to verify,
not evidence that those events occurred. This is a local implementation and
verification record, not production certification.

## Existing implementation and stage gates

| Stage | Repository evidence | Result and remaining gate |
| --- | --- | --- |
| 1: Safe Core foundation | `app/Backbone/Contracts`, `Data`, `Adapters`, `Commands`; Core read contracts; `/api/v1` resources; integration outbox; canonical organization scope and audit | Foundation already exists and is exercised by the selected tests. Preserve the existing `app/Backbone` convention rather than introduce a duplicate `app/Core` tree. All downstream reads have not migrated. |
| 2: Core workspace | Core login, permission-filtered navigation, scoped dashboard, assignments, integrations, audit and settings; legacy workspace compatibility | Existing implementation covered by Core workspace/page/dashboard and Golden Path feature tests. Human accessibility, bilingual language review and business UAT remain pending. |
| 3: Downstream migration | Contract adapters, Core-only write boundaries, event consumers, per-module dependency ratchet | Applied the next Cafeteria lookup increment below. Migration remains incomplete: 208 service-module files import Core models; list/resource relationship reads are also still present. |
| 4: Physical separation readiness | [Separation prerequisites](core-physical-separation.md), database ownership, service authentication, snapshot/freeze tooling, deployment and rollback runbooks | **Not ready.** Complete downstream migration and validate a representative staging environment before separating applications or databases. |
| 5: Production cutover | Existing [cutover runbook](core-cutover-runbook.md), write freeze, snapshots, integrity checks | **Blocked by Stage 4** and unverified target-environment gates. No production cutover, write freeze, data synchronization or legacy deletion was performed. |
| 6: Production maturity | Operational safeguard tests, production-readiness command, [production baseline](core-production-baseline.md), recovery and observability documentation | Design/tooling exists; production HA, recovery objectives, restore/failover and production-class capacity are not verified by this work. No city-scale readiness claim. |
| 7: Formal acceptance | [Acceptance matrix](../go-live/core-acceptance-matrix.md), [go-live gates](../go-live/go-live-gates.md), UAT and penetration-test plans | **Not ready for certification.** Business, data, security and operations approvals remain pending. Automated tests do not substitute for UAT, an independent penetration test or operational drills. |
| 8: Governance | Existing platform/API/event/release/security/data-stewardship governance, integration matrix, debt and deprecation registers, architecture CI guards | Governance documents and guards exist with gaps: named ownership, retention approvals, access/consumer reviews, production health evidence and remaining technical debt. This does not establish that the platform is live. |

## Applied changes

- `EmployeeCafeteriaExclusionController::store` resolves identity and organization
  access through `EmployeeDirectory`. Existing permission checks, unknown-employee
  handling, cross-organization denial and the authoritative exclusion action stay
  in place.
- `CafeteriaSubsidyLedgerController::index` resolves its selected employee through
  `EmployeeDirectory::findFor`. The existing employee response fields are mapped
  explicitly from the DTO. An employee without a current assignment still cannot
  be selected, even by a global administrator.
- `CafeteriaLedgerService::getBalance` accepts an employee UUID as well as an
  Employee model, allowing the migrated controller to use the DTO without breaking
  existing service callers. Balance calculation and ledger ordering are preserved.
- The Cafeteria dependency ratchet is reduced from 23 to 21 files; the overall
  import count falls from 210 to 208. The module matrix, separation prerequisites,
  technical debt register and platform-health scorecard reflect that change.
- Regression tests verify the contract is actually called, response compatibility,
  balance calculation, organization isolation, permission enforcement, exclusion
  creation and the unassigned-employee restriction.

These two lookups do not migrate the complete Cafeteria module. The ledger query
and exclusion resources still read employee relationships, and other Cafeteria
services continue to import Core models. No schema, data, UUID, card reference,
QR token, business rule, existing URL or deployment topology was changed.

No new Laravel class was needed, so no `artisan make:*` command or migration is
required for this increment. The pre-existing untracked
`docs/runbook/create-production-super-admin.sql` is unrelated to these stages.

## Verification

Final combined test result: **375 passed, 1 skipped, 2,101 assertions** in
46.54 seconds, exit code 0, using PHP 8.4 and the SQLite in-memory test database.

Reproduce with the supported project PHP executable:

```powershell
php vendor/pestphp/pest/bin/pest --colors=never `
    tests/Feature/Backbone tests/Feature/Core tests/Feature/Integration tests/Feature/GoldenPath `
    tests/Feature/CafeteriaLedgerPageTest.php tests/Feature/Security/CafeteriaExclusionScopeTest.php `
    tests/Unit/BackboneBoundaryTest.php tests/Unit/ServiceModuleCoreDependencyTest.php `
    tests/Unit/GovernanceDriftTest.php tests/Unit/MigrationSafetyTest.php `
    tests/Feature/Api/OrganizationEmployeeApiTest.php tests/Feature/Api/MasterBackboneRemediationApiTest.php `
    tests/Feature/Api/MachineWriteBoundaryTest.php tests/Feature/Api/IdCardIntegrationApiTest.php `
    tests/Feature/PublicIdCheckerTest.php tests/Feature/EthiopianCalendarTest.php `
    tests/Unit/EthiopianCalendarServiceTest.php tests/Feature/OrganizationScope/OrganizationScopeServiceTest.php `
    tests/Feature/Policies/CorePolicyTest.php tests/Feature/Security/AuditSealAndScopeGapsTest.php
```

The selected automated gates cover:

- Backbone contracts, identity stability, assignment lifecycle, occupancy, card
  lifecycle, event consumers and downstream audit context.
- Core login/navigation/dashboard/pages and legacy workspace coexistence.
- Outbox transaction rollback, change-feed scope/cursors, signed webhooks,
  retries, duplicate delivery, replay, destination safety and OpenAPI coverage.
- End-to-end Golden Path, public card privacy, machine write refusal, API scopes,
  organization isolation, policies, audit sealing and Ethiopian/Gregorian dates.
- Architecture dependency direction, downstream import ratchet, governance drift
  and additive migration safety.
- The changed Cafeteria exclusion and ledger paths.

`npm run typecheck`: passed.

`npm run i18n:audit`: passed; all 22,654 translation keys have Amharic values.
Existing advisory findings remain: 168 Latin-word warnings in Amharic values and
one deprecated-term warning. Language quality still requires human review.

`php vendor/bin/pint --test` on the six modified PHP files: passed.
`git diff --check`: passed.

Tests use the repository's SQLite in-memory configuration. A server-database
foreign-key test is deliberately skipped on SQLite because SQLite cannot add that
key to an existing table; this is not proof of MySQL/PostgreSQL compatibility.
The full repository suite, a browser accessibility review, production data audits,
load tests, penetration testing and restore/failover drills were not performed.

## Dependencies that prevent the later stages

1. Finish the module-by-module read migration in
   [module-integration-matrix.md](module-integration-matrix.md), including raw SQL
   and relationship reads, not only direct `use` statements.
2. Resolve the documented production database decision: the repository's health
   record identifies MySQL on the live host while the deployment decision names
   PostgreSQL (TD-19). This work did not connect to that host or independently
   re-measure its state. Run the gates on the chosen engine before release.
3. Provide a production-like staging deployment and perform the documented
   separation/cutover rehearsal, reconciliation, integration and rollback checks.
4. Record real backup/PITR, restore/failover, monitoring, capacity and recovery
   objective evidence; the current repository records these as open or unmeasured.
5. Obtain the required independent security review, business/data/operations
   sign-offs and named governance ownership. Keep those entries pending until the
   owners provide evidence.

The local changes can be rolled back by reverting the modified controllers,
ledger service, tests and documentation. No database rollback is required.

## Stage statuses

These describe the available repository evidence and this increment; they are not
a claim that all eight stages have been completed.

| Stage | Status |
| --- | --- |
| 1 | `STAGE_1_CORE_FOUNDATION_COMPLETE_WITH_WARNINGS` |
| 2 | `STAGE_2_CORE_WORKSPACE_COMPLETE_WITH_WARNINGS` |
| 3 | `STAGE_3_DOWNSTREAM_MIGRATION_BLOCKED` |
| 4 | `STAGE_4_PHYSICAL_SEPARATION_NOT_READY` |
| 5 | `STAGE_5_PRODUCTION_CUTOVER_BLOCKED` |
| 6 | `STAGE_6_DESIGN_READY_NOT_MEASURED` |
| 7 | `STAGE_7_GO_LIVE_NOT_READY` |
| 8 | `STAGE_8_PLATFORM_GOVERNANCE_ESTABLISHED_WITH_GAPS` |
