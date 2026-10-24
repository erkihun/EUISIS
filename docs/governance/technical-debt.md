# Technical debt register

Known debt with its guard and the way out. Reviewed monthly.

| ID | Debt | Guard today | Way out | Owner |
| --- | --- | --- | --- | --- |
| TD-1 | 210 service-module files read Golden Path models directly | `ServiceModuleCoreDependencyTest` ratchet per module | move each module onto Core contracts; lower the baseline | module owners |
| TD-2 | ~~Eight partner routes missing from the OpenAPI document~~ **resolved 2026-10-24**: documented (OpenAPI 1.4.0), backlog empty | `OpenApiContractTest` | — | integration owner |
| TD-3 | ~~Users with no scope rows see every organization~~ **resolved 2026-10-24** for organization-level roles (ADR 0003); role-less accounts with direct permissions remain, listed by `access:scope-gaps` | `OrganizationScopeServiceTest` | give those accounts a role and a scope | security owner |
| TD-4 | Legacy masters (institution offices, `users.national_id`, `api_clients`) | redirects, duplicate-masters report | [deprecation-register.md](deprecation-register.md) | data steward |
| TD-5 | `DesignImportTest` intermittent in parallel runs | — | isolate its fixtures | QA |
| TD-6 | Suites that fail only on MariaDB (field work, grievance aggregation, vacancy announcement, knowledge pages, organization feedback form, one Golden Path case) | PostgreSQL is the production engine | fix the engine-specific SQL or mark the engine | module owners |
| TD-7 | ~~esbuild low advisory in the build toolchain~~ **resolved 2026-10-24**: esbuild 0.28.2, `npm audit` 0 | — | — | release manager |
| TD-8 | No central log shipping or alert routing for production | hosting decision | choose with operations | operations lead |
| TD-9 | Physical Core separation not started | boundary and ownership documented | after TD-1 | architecture |
| TD-10 | Retention periods per data class undecided (NEEDS_POLICY_DECISION_RETENTION); RPO/RTO approved as targets but not validated | records are kept (no class is deleted without approval); readiness shows TARGET_NOT_YET_VALIDATED | approval by HR, Legal, Records Management, Audit, Data Protection; restore and failover drills | Data Owner, DR Coordinator |
| TD-11 | Module housekeeping jobs (communication, Intelligence, assistant pruning) not yet reviewed against the retention matrix | their own settings | include them in the retention review | Data Owner |
