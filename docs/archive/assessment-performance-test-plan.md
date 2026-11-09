# Assessment performance test plan

## Objective

Validate the Assessment domain at the target scale: approximately 180,000 employees, multiple institutions and units, several concurrent cycles, multiple evaluator schemes, and millions of historical response items. Use synthetic data only.

## Current design evidence

- Cycle generation dispatches a coordinator job and 500-row chunk jobs (`GenerateCycleAssessmentsJob`, `GenerateAssessmentChunkJob`).
- Eligibility snapshotting uses chunked queries; assignment generation uses a transaction per employee rather than one city-wide transaction.
- Oversight lists are paginated and aggregate services use SQL counts/grouping rather than loading the employee population into PHP.
- Queue driver is `database` in the audited environment. Worker count, timeout, retry, failed-job retention, and production Redis decision are not yet documented: NEEDS_DECISION.

## Synthetic dataset

| Dataset | Minimum shape |
|---|---|
| Population | 180,000 active employees across 100+ institutions and realistic unit hierarchy |
| Forms | 10 published versions, 3–8 sections each, 20–80 criteria, decimal options |
| Cycles | 3 active/historical cycles; at least one institution pilot and one city-wide cycle |
| Evaluators | manager, peer, committee, and manually configured examples; 2–5 responses per record |
| History | At least 3 million response items and finalized records/submissions/returns |

## Test matrix and acceptance thresholds

| ID | Workload | Measure | Acceptance criterion |
|---|---|---|---|
| PERF-01 | Eligibility snapshot for 180k employees | duration, peak DB CPU, row count reconciliation | Completes asynchronously/chunked; exact eligible/excluded reconciliation; no web-request timeout. |
| PERF-02 | Generate assessment assignments | queue throughput, duplicate records/responses, retry safety | One record per unique employee/type/period and one active evaluator response per assignment; retries create no duplicates. |
| PERF-03 | Evaluator worklist | p95 response time with concurrent evaluators | Paginated query; no cross-evaluator data; target set by operations before sign-off. |
| PERF-04 | Institution dashboard and employee drill-down | p95/p99, SQL plans, memory | Aggregates remain SQL-based; drill-down is paginated; no city-wide employee collection in application memory. |
| PERF-05 | City reports/distribution/gender | p95/p99, query plan, memory | Scoped filters use indexes; results reconcile to coverage totals. |
| PERF-06 | Large CSV/Excel/PDF export | queue time, memory, access control | Runs asynchronously; artifact is private, expiring, and scoped; CSV formula injection remains neutralized. |
| PERF-07 | Simultaneous submit/finalize/verify | error rate, duplicates, final states | Exactly one accepted transition/version; losers receive conflict/validation response. |

## Method

1. Capture `EXPLAIN (ANALYZE, BUFFERS)` for each aggregate and export query on an anonymized production-shaped PostgreSQL clone.
2. Run generation and export from queue workers while measuring worker RSS, database CPU/IO, lock waits, failed jobs, and retry counts.
3. Apply representative concurrency only: 500 evaluator submissions, 50 reviewer finalizations, and 20 city verification actions—not destructive unbounded traffic.
4. Record runtime, p50/p95/p99, memory, error counts, query plans, dataset seed revision, queue configuration, and hardware in the release evidence.

## Exit criteria

No unresolved duplicate, lock-timeout, unbounded-memory, cross-scope, or data-reconciliation defect is permitted. Operations must set numerical SLOs based on production capacity and approve the evidence. This plan has **not** been executed in the audited workspace.
