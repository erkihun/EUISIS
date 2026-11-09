# Situation Room — metric catalog

Unless noted otherwise, every metric shares these properties:

- **Source:** `employee_service_feedback`.
- **Visibility:** target-type grants, own submissions and `hidden` excluded
  ([situation-room-community-voice.md](situation-room-community-voice.md)).
- **Scope:** the user's organization scope plus the institution in view and
  its descendants ([situation-room-hierarchy-rollup.md](situation-room-hierarchy-rollup.md)).
- **Date field:** `submitted_at`. A period is start-inclusive and
  end-exclusive.
- **Filters:** type, target, category (or uncategorized), status bucket.
- **Freshness:** cached for 120 seconds per user scope and filters (300
  seconds on the Wall). *Last updated* shows when it was computed.

Calculation version: `SituationRoomMetrics::VERSION = v1`. Changing a definition
changes the version, and so every cache key.

| Code | English | Amharic | Definition | Calculation | Drill-down | Permission |
|---|---|---|---|---|---|---|
| `feedback` | Feedback | አስተያየቶች | Submissions of type feedback | `SUM(type='feedback')` | Category, institution | `situation_room.view` |
| `suggestion` | Suggestions | ጥቆማዎች | Submissions of type suggestion | `SUM(type='suggestion')` | same | same |
| `complaint` | Complaints | ቅሬታዎች | Submissions of type complaint | `SUM(type='complaint')` | same | same |
| `unresolved` | Unresolved | ያልተፈቱ | Status in the unresolved set | `SUM(status IN unresolved)` | Status filter | same |
| `resolved` | Resolved | የተፈቱ | Status in the resolved set | `SUM(status IN resolved)` | Status filter | same |
| `unrouted` | Unrouted | ያልተላለፉ | Status `unrouted` | `SUM(status='unrouted')` | Status filter | same |
| `unresolved_complaints` | Unresolved complaints | ያልተፈቱ ቅሬታዎች | Complaints still being worked on | `SUM(type='complaint' AND status IN unresolved)` | Institution table | same |
| `avg_rating` | Average rating | አማካይ ደረጃ | Mean of 1–5 ratings on feedback | `AVG(rating)` over feedback with a rating | Ratings card | same |
| `change` | Change against previous period | — | `(current − previous) / previous`. Not shown when previous = 0. | Two KPI queries | — | same |
| `trend` | Community feedback trend | የሕዝብ አስተያየት አዝማሚያ | Count per type per bucket | `SqlDateBucket`: day ≤ 31 days, week (Monday) ≤ 120 days, otherwise month. Empty buckets are filled with 0. | — | same |
| `by_institution` | By institution | በተቋም | Counts per direct child, including its descendants, plus own | Grouped join on `organization_closure_paths` (depth ≥ 0) | Opens that institution | same |
| `by_category` | By category | በምድብ | Counts per category. `NULL` = uncategorized. | `GROUP BY category_id` | Category filter | same |
| `by_service` | Services named most often | በብዛት የተጠቀሱ አገልግሎቶች | Top 10 position services | `GROUP BY position_service_id` | — | same |
| `resolution` | Where submissions stand | የቀረቡ ጉዳዮች ያሉበት ሁኔታ | Status buckets; resolved and unresolved per type | `GROUP BY status, type` | Status filter | same |
| `targets` | About an employee or an institution | ሠራተኛን ወይም ተቋምን የሚመለከቱ | Type counts per target | `GROUP BY COALESCE(target_type,'employee')` | Target filter | same |
| `ratings` | Feedback ratings | የአስተያየት ደረጃዎች | Distribution of 1–5 | `GROUP BY rating` | — | same |
| `employee_targeted` | Employee-targeted submissions | ሠራተኛን የሚመለከቱ | Counts per employee, 25 per page, latest first | `GROUP BY employee_id` | Employee page | `situation_room.view_employee_targeted` + `service_feedback.view_employee_target` |
| `attention.*` | Attention required | ትኩረት የሚያስፈልጋቸው | Rules in [executive-situation-room.md](executive-situation-room.md#attention-rules) | One query per rule. Rules need configured values. | "Show these" filter | `situation_room.view` |
| `configuration` | Taking part | ተሳትፎ | Active institutions in view without an active feedback QR, or without a public phone | `organizations` + `organization_feedback_qr_codes` | — | `situation_room.view` |

## Supporting indicators

These do not come from public feedback. Each card shows only with its
module's own view permission, and each fails on its own.

| Card | Source | Shown with | Figures |
|---|---|---|---|
| Workforce | `DashboardMetricService` (employee status counts, position occupancy) | `employees.view` + `positions.view` | Employees, active employees, occupied and vacant positions |
| Performance agreements | `employee_performance_agreements` by status | `performance_reports.view` | Awaiting agreement, agreed or active, under review, finalized |
| Daily work (last 7 days) | `daily_activity_logs` by status | `daily_activities.view_reports` | Awaiting review, returned, approved |
| Field work | `field_work_requests` (open statuses) | `field_work.view_org` | Awaiting approval, approved, in the field. No locations. |
| Cafeteria | `cafeteria_transactions` accepted today, placed by the employee's current institution | `cafeteria_reports.view` | Meals served today |

No score sums, no GPS, no transport.

## Not provided (on purpose)

- **Rates** (complaints per 1,000 services): there is no denominator
  (NEEDS_DATA N1).
- **Rankings or league tables** of employees or institutions.
- **Average time to resolve**: `resolved_at` exists, but no response-time
  policy has been agreed. It should arrive together with D2.
