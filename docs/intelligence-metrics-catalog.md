# Addis One Intelligence — metrics catalog

Every figure an Intelligence area shows, with its exact definition, so a
reader can recompute it and a reviewer can challenge it. Business metrics are
computed by `app/Services/Intelligence/Business/*` on the Situation Room
blocks (`docs/intelligence-business.md`); system metrics by
`app/Services/Intelligence/System/*`. Labels are the `intelligence.kpi.*` keys
of `lang/{en,am}/intelligence_business.php`.

Conventions: **scope rule** is always "confined with `SituationScope::apply()`
on the named column before counting" unless stated; **period** is the view's
`SituationPeriod` (inclusive whole days, application timezone); **freshness**
is the `SituationCache` TTL (`situation_room.cache_seconds`, default 300 s)
and the payload carries `generated_at`; **owner** is the service that owns the
source records.

## Business metrics

| Code | English | Amharic | Definition | Source table(s) | Numerator / denominator | Time basis | Scope rule | Filters | Null handling | Freshness | Drill-down | Owner |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `required_fte` | Required FTE | የሚያስፈልግ FTE | Sum of `required_fte` over requirements of APPROVED manpower plans | `position_requirements`, `manpower_plans` | SUM(required_fte) where `pr.status IN (approved, implemented)` and `mp.status = approved`, `mp.deleted_at IS NULL` / — | as of now | `pr.organization_id` | `organization_unit_id` | null when no approved requirement in scope | cache TTL | Manpower plans | Organization Design |
| `approved_headcount` | Approved headcount | የጸደቀ የሠራተኛ ብዛት | Sum of `approved_headcount` over the same requirements | same | SUM(approved_headcount) / — | as of now | `pr.organization_id` | `organization_unit_id` | null when no approved requirement | cache TTL | Manpower plans | Organization Design |
| `approved_positions` | Approved positions | የጸደቁ የሥራ መደቦች | Active positions with an approved establishment effective today | `positions`, `position_establishments` | COUNT(DISTINCT p.id) where `pe.status = approved`, not deleted, `DATE(effective_from) ≤ today`, `effective_to` null or ≥ today / — | today | `p.organization_id` | `organization_unit_id` | 0 | cache TTL | Positions | Positions |
| `approved_slots` | Approved slots | የጸደቁ ቦታዎች | Sum of `approved_slots` of those establishments | same | SUM(pe.approved_slots) / — | today | `p.organization_id` | `organization_unit_id` | 0 | cache TTL | Positions › Establishment | Positions |
| `capacity` | Capacity | አቅም | Rule D-2: approved slots effective today, or 1 per active position without an establishment | `positions`, `position_establishments` | SUM(CASE slots > 0 THEN slots ELSE 1) / — | today | `p.organization_id` | `organization_unit_id` | 0 | cache TTL | Positions | Positions |
| `occupied_positions` | Occupied positions | የተያዙ የሥራ መደቦች | Slots held by current active assignments, capped at capacity per position | + `employee_assignments` | SUM(MIN(held, capacity)) / — | today | `p.organization_id` | `organization_unit_id` | 0 | cache TTL | Positions | Positions |
| `vacant_positions` | Vacant positions | ክፍት የሥራ መደቦች | Capacity minus occupants per position, floored at 0 | same | SUM(MAX(capacity − held, 0)) / — | today | `p.organization_id` | `organization_unit_id` | 0 | cache TTL | Positions | Positions |
| `employee_count` | Employee count | የሠራተኛ ብዛት | Current active assignments (one per employee) | `employee_assignments` | COUNT(*) where `is_current` and `assignment_status = active` / — | today | `ea.organization_id` | `organization_unit_id` | 0 | cache TTL | Employees | Employees |
| `vacancy_rate` | Vacancy rate | የክፍት ቦታ መጠን | Vacant share of capacity | derived | vacant / capacity × 100 | today | as above | as above | null when capacity 0 | cache TTL | Positions | Positions |
| `positions` | Active positions | ንቁ የሥራ መደቦች | Active, non-deleted positions | `positions` | COUNT(*) / — | now | `p.organization_id` | `organization_unit_id` | 0 | cache TTL | Positions | Positions |
| `multiple_current_assignments` | Employees with more than one current assignment | ከአንድ በላይ የአሁን ምደባ ያላቸው ሠራተኞች | Employees holding several current active assignments | `employee_assignments` | COUNT of employee_id groups HAVING COUNT(*) > 1 / — | now | `ea.organization_id` | `organization_unit_id` | 0 | cache TTL | Employees | Employees |
| `active_without_assignment` | Active employees without a current assignment | የአሁን ምደባ የሌላቸው ንቁ ሠራተኞች | Employees with status active and no current active assignment, attributed through any past assignment in scope | `employees`, `employee_assignments` | COUNT(*) / — | now | EXISTS(any assignment in scope); none for unrestricted | — | 0 | cache TTL | Employees | Employees |
| `positions_over_capacity` | Positions above capacity | ከአቅም በላይ የሆኑ የሥራ መደቦች | Positions whose current occupants exceed capacity | `positions`, `position_establishments`, `employee_assignments` | COUNT(*) where held > capacity / — | today | `p.organization_id` | — | 0 | cache TTL | Positions | Positions |
| `positions_without_jd` | Positions without a job description | የሥራ መግለጫ የሌላቸው የሥራ መደቦች | Active positions with no JD in force (published/superseded, effective today) | `positions`, `job_descriptions` | COUNT(*) NOT EXISTS / — | today | `p.organization_id` | `organization_unit_id` | 0 | cache TTL | Positions | Organization Design |
| `positions_without_competency` | Positions without competency requirements | የብቃት መስፈርት የሌላቸው የሥራ መደቦች | Active positions with no `position_competency_requirements` row | `positions`, `position_competency_requirements` | COUNT(*) NOT EXISTS / — | now | `p.organization_id` | `organization_unit_id` | 0 | cache TTL | Positions | Organization Design |
| `jd_coverage` | Job description coverage | የሥራ መግለጫ ሽፋን | Share of active positions with a JD in force | derived | (positions − without_jd) / positions × 100 | today | as above | as above | null when no positions | cache TTL | Positions | Organization Design |
| `transfers_inbound` / `_outbound` / `_internal` / `_net` | Inbound / Outbound / Internal / Net | ገቢ / ወጪ / ውስጣዊ / የተጣራ | Completed transfers arriving from outside the scope, leaving it, moving inside it; net = inbound − outbound | `employee_transfers` | COUNT(*) where `status = completed`, `completed_at` in period / — | period (`completed_at`) | `from_organization_id` / `to_organization_id` against the scope id list; unrestricted → all internal | — | 0 | cache TTL | Transfers | Transfers |
| `assignment_starts` | Assignment starts | የምደባ ጅማሬዎች | Assignments whose `effective_from` falls in each month | `employee_assignments` | COUNT(*) per `SqlDateBucket` month / — | last 12 months | `ea.organization_id` | `organization_unit_id` | 0 per empty month | cache TTL | Employees | Employees |
| `complaints_received` / `tip_offs_received` / `feedback_received` | … received | የተቀበሉ … | Submissions of the type with `submitted_at` in the period, status ≠ hidden | `employee_service_feedback` | COUNT(*) per `type` / — (previous period as `previous`) | period (`submitted_at`) | `f.organization_id` + target grants (`PublicFeedbackVisibility`) | status, category_id, position_service_id, organization_unit_id, target_type | 0 | cache TTL | Submissions | Public Feedback |
| `complaints_open` | Complaints open | ክፍት ቅሬታዎች | Complaints submitted by period end with no outcome by then | same | COUNT(*) where `submitted_at ≤ end` and `COALESCE(resolved_at, closed_at)` null or > end / — | period end | as above | as above | 0 | cache TTL | Submissions | Public Feedback |
| `open_at_period_start` / `open_at_period_end` | Open at period start / end | በጊዜው መጀመሪያ / መጨረሻ ክፍት | Same rule at the two ends | same | as above | period bounds | as above | as above | 0 | cache TTL | Submissions | Public Feedback |
| `backlog_change` | Backlog change | የክምችት ለውጥ | Percent change of open complaints across the period | derived | (end − start) / start × 100 | period | — | — | null when start 0 | cache TTL | — | Public Feedback |
| `unrouted` / `unrouted_share` | Unrouted / Unrouted share | ያልተመሩ / ያልተመሩ ድርሻ | Tip-offs and complaints of the period in status `unrouted` | same | COUNT(status = unrouted) / COUNT(type IN (suggestion, complaint)) × 100 | period | as above | as above | share null when none routable | cache TTL | Submissions | Public Feedback |
| `services_active` | Active services | ንቁ አገልግሎቶች | Active, non-deleted position services | `position_services` | COUNT(*) / — | now | `s.organization_id` | — | 0 | cache TTL | Services | Services |
| `services_with_activity` | Services with recorded work | የተመዘገበ ሥራ ያላቸው አገልግሎቶች | Distinct `position_service_id` on items of reported (non-draft) days in the period | `daily_activity_logs`, `daily_activity_items` | COUNT(DISTINCT i.position_service_id) / — | period (`activity_date`) | `l.organization_id` | `organization_unit_id` | 0 | cache TTL | Daily Activity | Daily Activity |
| `service_activities` | Register items naming a service | አገልግሎት የሚጠቅሱ የመዝገብ ዕቃዎች | Items with a `position_service_id` on reported days in the period | same | COUNT(*) / — | period | `l.organization_id` | `organization_unit_id` | 0 | cache TTL | Daily Activity | Daily Activity |
| `services_with_public_voice` | Services named by the public | በሕዝብ የተጠቀሱ አገልግሎቶች | Distinct `position_service_id` on the period's submissions | `employee_service_feedback` | COUNT(DISTINCT) / — | period | `f.organization_id` | — | 0 | cache TTL | Submissions | Public Feedback |
| `submissions_linked_share` | Submissions naming a service | አገልግሎት የሚጠቅሱ አስተያየቶች | Share of the period's submissions with `position_service_id` or `service_id` | same | linked / total × 100 | period | `f.organization_id` | — | null when no submissions | cache TTL | Submissions | Public Feedback |
| `submissions_unlinked` | Submissions naming no service | አገልግሎት የማይጠቅሱ አስተያየቶች | total − linked | same | COUNT / — | period | as above | — | 0 | cache TTL | Submissions | Public Feedback |
| `plans_published` | Plans published | የታተሙ ዕቅዶች | Performance plans in a current cycle with status PUBLISHED | `performance_plans`, `performance_cycles` | COUNT(*) / — | current cycles | `t.organization_id` | — | NO_CURRENT_CYCLE | cache TTL | Performance | EPMS |
| `agreements_in_place` | Agreements in place | በሥራ ላይ ያሉ ስምምነቶች | Distinct employees with an agreement in AGREED/ACTIVE/UNDER_REVIEW/FINALIZED/CLOSED in a current cycle | `employee_performance_agreements` | COUNT(DISTINCT employee_id) / — | current cycles | `t.organization_id` | — | NO_CURRENT_CYCLE | cache TTL | Performance | EPMS |
| `agreement_coverage` | Agreement coverage | የስምምነት ሽፋን | Agreements in place as a share of employee count | derived | agreements_in_place / employee_count × 100 | current cycles | as above | — | null without a current cycle or headcount | cache TTL | Performance | EPMS |
| `reviews_completed` | Reviews completed | የተጠናቀቁ ግምገማዎች | Reviews with status COMPLETED on agreements of current cycles | `performance_reviews`, `employee_performance_agreements` | COUNT(*) / — | current cycles | `a.organization_id` | — | NO_CURRENT_CYCLE | cache TTL | Performance | EPMS |
| `results_released` | Results released | የተለቀቁ ውጤቶች | Current results with status RELEASED | `performance_results` | COUNT(*) where `is_current` / — | current cycles | `t.organization_id` | — | NO_CURRENT_CYCLE | cache TTL | Performance | EPMS |
| `calibration_open` | Calibration sessions open | ክፍት የማስተካከያ ስብሰባዎች | Sessions in DRAFT or IN_PROGRESS | `performance_calibration_sessions` | COUNT(*) / — | current cycles | `t.organization_id` | — | NO_CURRENT_CYCLE | cache TTL | Performance | EPMS |
| `appeals_pending` | Appeals pending | በመጠባበቅ ላይ ያሉ ይግባኞች | Appeals in SUBMITTED or UNDER_REVIEW | `performance_appeals` | COUNT(*) / — | current cycles | `t.organization_id` | — | NO_CURRENT_CYCLE | cache TTL | Performance | EPMS |
| `agreements_overdue` | Agreements overdue | ጊዜያቸው ያለፈ ስምምነቶች | Agreements in DRAFT/PENDING_*/RETURNED in current cycles whose `planning_end_date` < today | `employee_performance_agreements`, `performance_cycles` | COUNT(*) / — | today vs cycle dates | `t.organization_id` | — | NEEDS_DATA when no cycle has phase dates | cache TTL | Performance | EPMS |
| `midyear_reviews_overdue` / `yearend_reviews_overdue` | Mid-year / Year-end reviews overdue | ጊዜያቸው ያለፈ … ግምገማዎች | Reviews of that type not COMPLETED in cycles whose review end date < today | `performance_reviews`, agreements, cycles | COUNT(*) / — | today vs cycle dates | `a.organization_id` | — | NEEDS_DATA | cache TTL | Performance | EPMS |
| `overdue_steps` | Overdue steps | ጊዜያቸው ያለፈ ደረጃዎች | Sum of the three above | derived | SUM | — | — | — | null when NEEDS_DATA | cache TTL | Performance | EPMS |
| `reporting_gaps` | Targets without actuals | ትክክለኛ ውጤት የሌላቸው ዒላማዎች | Current `kpi_targets` of PUBLISHED plans in current cycles whose period overlaps the view's, with no `kpi_actuals` overlapping it | `kpi_targets`, `performance_plans`, `kpi_actuals` | COUNT(*) NOT EXISTS / — | period overlap on `period_start/end` | `pp.organization_id` | — | 0 | cache TTL | Performance | EPMS |
| `forecast_complaints_next_month` | Complaints next month (baseline) | በሚቀጥለው ወር ቅሬታዎች (መነሻ) | First forecast point of the linear baseline over the last 12 complete months of complaints (from the first recorded month) | `employee_service_feedback` | least squares | 12 complete months → +1 | `f.organization_id` + target grants | — | null when forecasting off or < 6 points | cache TTL | Forecasting | Intelligence |
| `forecast_vacancies_next_month` | Vacancies next month (baseline) | በሚቀጥለው ወር ክፍት ቦታዎች (መነሻ) | Same over vacant slots reconstructed as of each month end (rule D-2 with the effective windows of establishments and assignments, positions active today) | `positions`, `position_establishments`, `employee_assignments` | least squares | 12 complete months → +1 | `p.organization_id` | — | null when off or < 6 points | cache TTL | Forecasting | Intelligence |
| `checks_run` / `checks_failing` / `checks_warning` / `records_flagged` | Checks run / failing / warning; records flagged | የተካሄዱ / የወደቁ / ማስጠንቀቂያ የሰጡ ፍተሻዎች፤ ምልክት የተደረገባቸው መዝገቦች | Number of data-quality checks; checks with severity FAIL / WARN and count > 0; sum of all counts | see `docs/intelligence-business.md` checks table | COUNT / SUM | now | per check | — | 0 | cache TTL | Data Quality | Intelligence |

Insight parameters (`:current`, `:baseline`, `:percent`, `:count`, `:threshold`,
`:failure_rate`, `:incidents`, `:name`, `:period`) are the figures of the
evidence rows of the same insight; every insight names its `metric` from this
table and its `source`.

## System metrics

Technical metrics are collected by `TelemetryCollector` into
`intelligence_signals` every five minutes (`docs/intelligence-system-health.md`).
Scope: the whole platform (no organization scope). Freshness: five-minute
buckets, pages read the latest sample or aggregate the window (hour/day).
Null handling: a metric with no sample is UNKNOWN, never zero. Owner: platform
operations.

| Code | English | Amharic | Definition | Source | Numerator / denominator | Time basis | Filters | Drill-down |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `request.total` | Requests | ጥያቄዎች | HTTP responses counted (exact on an in-memory cache store, 1-in-20 sampled and scaled otherwise) | request counters | count | 5-min bucket, per route group | route group | Application Performance |
| `request.s5xx` | Server errors | የአገልጋይ ስህተቶች | Responses with status ≥ 500 (always exact) | request counters | count | 5-min bucket, per route group | route group | Error & Failure Analysis |
| `request.error_rate_percent` | Server-error rate | የአገልጋይ ስህተት መጠን | 100 × s5xx ÷ total per bucket | request counters | s5xx / total | 5-min bucket | route group | Application Performance |
| `request.latency_p95_ms` | p95 latency | p95 መዘግየት | Upper bound of the latency bucket (100/300/1000/3000/5000 ms) that reaches 95 % of sampled requests | request counters | — | 5-min bucket | route group | Application Performance |
| `request.latency_avg_ms` | Average latency | አማካይ መዘግየት | dur_sum ÷ sampled total | request counters | ms | 5-min bucket | route group | Application Performance |
| `request.slow` | Slow responses | የዘገዩ ምላሾች | Responses slower than 1,000 ms (exact) | request counters | count | 5-min bucket | route group | Application Performance |
| `request.s401` / `s403` / `s429` | Denied / forbidden / throttled | የተከለከሉ / የተገደቡ | Responses with that status (exact) | request counters | count | 5-min bucket | route group | System Health (security) |
| `db.ping_ms` | Database probe | የመረጃ ቋት ፍተሻ | Time of `select 1` | collector | ms | sample | — | Database Health |
| `db.connections_percent` | Connections used | ጥቅም ላይ የዋሉ ግንኙነቶች | 100 × active ÷ max_connections (PostgreSQL / MySQL only) | `pg_stat_activity`, `SHOW STATUS` | active / max | sample | — | Database Health |
| `db.long_running_queries` | Long-running queries | ረጅም ጊዜ የሚወስዱ መጠይቆች | Active statements older than `INTELLIGENCE_LONG_QUERY_SECONDS` | statistics views | count | sample | — | Database Health |
| `db.waiting_locks` | Waiting locks | የሚጠብቁ መቆለፊያዎች | `pg_locks` not granted (PostgreSQL) | `pg_locks` | count | sample | — | Database Health |
| `db.deadlocks_total` | Deadlocks (cumulative) | ሞት መቆለፊያዎች | `pg_stat_database.deadlocks`; pages show the delta over the window | `pg_stat_database` | count | cumulative | — | Database Health |
| `cache.available` / `cache.ping_ms` | Cache available / probe | መሸጎጫ ይገኛል / ፍተሻ | Probe key written and read back; time taken | collector | 1/0, ms | sample | — | Redis & Queue Health |
| `redis.available` / `redis.ping_ms` | Redis available / probe | Redis ይገኛል / ፍተሻ | `PING`; only where Redis is configured | collector | 1/0, ms | sample | — | Redis & Queue Health |
| `queue.pending` | Pending jobs | በመጠባበቅ ላይ ያሉ ሥራዎች | `jobs` rows not reserved, per queue | `jobs` | count | sample, per queue | queue | Redis & Queue Health |
| `queue.oldest_age_seconds` | Oldest waiting job | የቆየው የሚጠብቅ ሥራ | now − min(available_at) per queue | `jobs` | seconds | sample, per queue | queue | Redis & Queue Health |
| `queue.failed_5m` / `queue.failed_total` | Failed jobs | ያልተሳኩ ሥራዎች | `failed_jobs` failed in the last 5 min / in total | `failed_jobs` | count | 5-min / cumulative | — | Redis & Queue Health |
| `scheduler.heartbeat` | Scheduler heartbeat | የመርሐግብር ምት | 1 when the collector ran; a gap is a missed schedule | collector | 1 | 5-min | — | System Health |
| `storage.writable` / `storage.free_percent` | Storage writable / free | ማከማቻ ሊጻፍበት የሚችል / ነፃ | Probe file written; free ÷ total of the storage volume | filesystem | 1/0, % | sample | — | System Health |
| `backup.health` / `backup.age_hours` | Backup health / age | የመጠባበቂያ ጤንነት / ዕድሜ | Backup module verdict (0 healthy … 3 unknown); age of the latest backup | `BackupStatusService` | code, hours | sample | — | Backup & Recovery |
| `integration.calls` / `integration.failures` | Provider calls / failures | የአቅራቢ ጥሪዎች / ውድቀቶች | `external_service_usages` rows in the bucket, per provider; status `failed` | usage records | count | 5-min, per provider | provider | Integration Health |
| `integration.api_requests` / `integration.api_failures` | External API requests / failures | የውጭ API ጥያቄዎች / ውድቀቶች | `api_request_logs` rows; `success = false` | API request log | count | 5-min | — | Integration Health |
| `integration.broadcast_failures_total` | Broadcast failures | የስርጭት ውድቀቶች | Communication broadcaster failure counter (cumulative) | cache counter | count | cumulative | — | Integration Health |
| `auth.mfa_failures` | MFA failures | የMFA ውድቀቶች | `audit_logs` `mfa.challenge_failed` in the bucket | audit log | count | 5-min | — | System Health (security) |
| `sessions.active` | Active sessions | ንቁ ክፍለ ጊዜዎች | `sessions` rows with a user active in the last 15 min (database driver) | `sessions` | count | sample | — | System Health |

Derived health states (`ComponentHealth`): healthy / degraded / unhealthy /
unknown per component, from the freshest sample within 15 minutes and the
active incidents on the component.

## Alerts and forecasts

| Record | Definition | Source | Freshness | Scope | Page |
| --- | --- | --- | --- | --- | --- |
| `intelligence_alerts` (technical) | one per active incident fingerprint; occurrences, clear runs, cooldown | incidents | hourly | platform | Alerts |
| `intelligence_alerts` (business) | one per Workforce / Public Voice / Performance / Data Quality insight of severity >= medium | business analyses, city-wide | hourly | city (unrestricted viewers only) | Alerts |
| `intelligence_forecasts.complaints_monthly` | predicted complaints per month, 3 months ahead, linear fit over up to 12 complete months | `ForecastingAnalysis::complaintsSeries` | daily | city | Forecasting, Governance |
| `intelligence_forecasts.vacancies_monthly` | predicted vacant positions per month, 3 months ahead | `ForecastingAnalysis::vacanciesSeries` | daily | city | Forecasting, Governance |
| forecast `actual`, `abs_error`, `pct_error` | filled once the target month has passed and has data; MAE / MAPE per metric on Governance | same series | daily | city | Governance |

Null handling: a forecast under six monthly points is not written
(insufficient); an unevaluated month stays null, never zero. See
`docs/intelligence-alerts.md`.
