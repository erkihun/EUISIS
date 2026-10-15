# Executive Situation Room — Metrics Catalog

Every figure, its source and its definition. "In scope" always means the
organizations resolved for the viewer (see `situation-room-access-control.md`),
applied before counting. "Period" is the selected period in the application
timezone, inclusive of both days. Nothing is estimated: a figure the data does
not support is shown as **NEEDS_DATA**, an unconfigured rule as
**CONFIGURATION_REQUIRED**.

## Shared definitions

| Term | Definition |
| --- | --- |
| Headcount | Distinct employees with a current (`is_current`) and `active` assignment in scope (rule D-2, `PositionCapacityService`). |
| Capacity | Per active position: approved establishment slots effective today, or 1 if none. |
| Filled / Vacant / Above capacity | Occupants capped at capacity / capacity minus occupants / occupants beyond capacity (never offsets a vacancy). |
| Gender | `male` / `female` / `unknown`, normalised with `AssessmentCoverageService::GENDER_VALUES`. |
| Age band | From `date_of_birth` in SQL; < 15 or > 100 years counts as unknown. |
| Received | Public submissions of the type with `submitted_at` in the period, moderation-hidden excluded. |
| Open | `pending`, `unrouted`, `assigned`, `under_review`, `reviewed` — as of now. |
| Concluded | `resolved_at` (or `closed_at`) in the period. |
| Roll-up row | The focus's own office plus each direct child including its subtree (published hierarchy). Name order, never by value. |

## By category

**Overview** — headcount, vacancy rate (workforce); complaints and Tip-offs received, average rating (public voice); reporting rate, field work under way (operations); items needing attention (sum of configured rules); open actions. Each links to its category; each uses the same query as the category.

**Workforce** — KPIs above; headcount, filled/vacant and gender by roll-up row; gender; age bands; employment type; employee status; occupations (position's occupation, top-N + Other + Not linked); grade levels; Education and Length of service → NEEDS_DATA (not recorded per employee).

**Structure** — organizations, units, positions, capacity, headcount; organizations by type; units by type; staffing table per roll-up row with vacancy rate; hierarchy notice (HIERARCHY_PUBLISHED or NEEDS_POLICY_DECISION_REPORTING_HIERARCHY).

**Service Delivery** — active position services; services named by the public; share of submissions naming a service; activities recorded against a service; work recorded by service (top-N); per-service table (feedback count, average rating, complaints, Tip-offs) in name order, or SERVICE_LINKAGE_REQUIRED.

**Complaints / Feedback / Tip-offs** (one implementation) — received (with previous period), open now, concluded, average days to outcome; trend; received and still open per roll-up row; handling stage (open / outcome recorded / closed); employee vs institution; category (top-N); service (or SERVICE_LINKAGE_REQUIRED); intake channel; Feedback: average rating and 1–5 distribution; Tip-offs: Corruption / Improper practice / Unclassified (older) — the three always add up; employee table (grant + focus required).

**Daily Operations** — days reported (status past draft, by `activity_date`), people reporting, reporting rate (people / headcount), late days, awaiting review (submitted, under_review, resubmitted — now), returned for correction, hours recorded; trend; people reporting vs headcount per row; day status; activity categories; activity progress.

**Performance** — for cycles marked current: plans published, people with an agreement and coverage, results released, appeals open; plans / agreements / current results / appeals by status; released rating distribution with small-group rule. No current cycle → NO_CURRENT_CYCLE.

**Movement** — completed transfers in the period split inbound (from outside the scope), outbound, internal; pending transfers; positions moved between units; trend; arrivals and departures per row; transfers by status; employment-status changes attributed to the organization the person was assigned to on that day.

**Field Work** — under way now, people in the field now, past expected return, awaiting approval, started and completed in the period; trend; per row; status; destination type; type; map → GIS_DATA_NOT_AVAILABLE (no coordinates) or MAP_LAYER_NOT_CONFIGURED.

**Cafeteria** — meals served (accepted scans by `transaction_date`), people served, refused, awaiting review, subsidy and employee contribution totals (ETB); trend; per row (employee's organization); outcome; by provider; refusal reasons as recorded.

**Grievance, Discipline & Appeal** — counts only, with the "four separate procedures" notice: grievances filed / open / concluded, grievance appeals filed, tribunal referrals, disciplinary cases open / charged, performance appeals open; grievances by stage (intake, being handled, decision issued, concluded); disciplinary status (small-group rule); open caseload per row (small-group rule); grievance trend. Never names, subjects or decisions.

**Critical Attention** — five configurable rules (System Settings › Situation Room): complaint open > N days; Tip-off unassigned > N days; daily report awaiting review > N days; field work > N hours past return; organizations with vacancy rate ≥ N %. Unset → CONFIGURATION_REQUIRED, nothing counted; zero → ALL_CLEAR.

**Trends & Comparison** — this period vs the previous one of equal length; twelve months of community voice and operations (monthly); headcount over time → NEEDS_DATA (not snapshotted); roll-up rows side by side in name order.

**Data Quality** — counts with base and share: gender missing, birth date missing/implausible, phone missing, photo missing, employment type missing, assignment without position, more than one current assignment, organizations with people above capacity, positions without establishment, Tip-offs/complaints without category, older Tip-offs without type, organizations not in the published hierarchy; each with where it is fixed.

## Language rules

Counts are what was **received**; nothing is a finding, a score of conduct or a
ranking. Organization rows are in name order; top-N is used only for
compositions (categories, occupations, services), never to order
organizations or people.
