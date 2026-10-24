# Technical debt register

Known debt with its guard and the way out. Reviewed monthly.

| ID | Debt | Guard today | Way out | Owner |
| --- | --- | --- | --- | --- |
| TD-1 | 210 service-module files read Golden Path models directly | `ServiceModuleCoreDependencyTest` ratchet per module | move each module onto Core contracts; lower the baseline | module owners |
| TD-2 | Eight partner routes missing from the OpenAPI document | `OpenApiContractTest` backlog list | document them; shrink the list | integration owner |
| TD-3 | Users with no scope rows see every organization | documented decision pending | assign scopes to every account, then fail closed | security owner |
| TD-4 | Legacy masters (institution offices, `users.national_id`, `api_clients`) | redirects, duplicate-masters report | [deprecation-register.md](deprecation-register.md) | data steward |
| TD-5 | `DesignImportTest` intermittent in parallel runs | — | isolate its fixtures | QA |
| TD-6 | Suites that fail only on MariaDB (field work, grievance aggregation, vacancy announcement, knowledge pages, organization feedback form, one Golden Path case) | PostgreSQL is the production engine | fix the engine-specific SQL or mark the engine | module owners |
| TD-7 | esbuild low advisory in the build toolchain | build-time only | upgrade with the next Vite update | release manager |
| TD-8 | No central log shipping or alert routing for production | hosting decision | choose with operations | operations lead |
| TD-9 | Physical Core separation not started | boundary and ownership documented | after TD-1 | architecture |
| TD-10 | RPO/RTO and personal-data retention undecided | nothing is purged | business decision | business owner |
