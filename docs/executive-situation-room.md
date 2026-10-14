# Executive Situation Room — Community Voice by Institution

> This page is one view of the Executive Situation Room (የአመራር ሁኔታ መከታተያ ማዕከል).
> The room's launcher, its sixteen categories, reports and wall are described in
> [situation-room-architecture.md](situation-room-architecture.md).

One page where a leader at any level sees what the public is saying about
service delivery in the institutions they are responsible for, and where
management attention is needed. It is built on the public feedback data that
already exists (feedback, suggestions and complaints, about an employee or
about an institution). It adds no new intake and changes no workflow.

Related documents:

| Topic | Document |
|---|---|
| What is counted and how it is worded | [situation-room-community-voice.md](situation-room-community-voice.md) |
| How institutions roll up | [situation-room-hierarchy-rollup.md](situation-room-hierarchy-rollup.md) |
| Every metric, its source and its definition | [situation-room-metrics.md](situation-room-metrics.md) |
| Permissions, privacy, audit, exports | [situation-room-security.md](situation-room-security.md) |
| Wall display | [situation-room-wall-mode.md](situation-room-wall-mode.md) |
| Measured timings and query plans | [situation-room-performance.md](situation-room-performance.md) |

## Pages

| Route | Page | Permission |
|---|---|---|
| `GET /situation-room` | The room's category launcher (see [situation-room-architecture.md](situation-room-architecture.md)) | `situation_room.view` |
| `GET /situation-room/community-voice` | This overview for everything in the user's scope | `situation_room.view` and `situation_room.public_voice.view` |
| `GET /situation-room/community-voice/organizations/{organization}` | The same overview for one institution and everything under it | as above, and the institution in scope |
| `GET /situation-room/targeted-employees` | Employee-targeted counts per employee | `situation_room.view_employee_targeted` and `service_feedback.view_employee_target` |
| `GET /situation-room/targeted-employees/{employee}` | One employee's submissions; text only with `situation_room.view_detail` | as above |
| `GET /situation-room/wall` | The room's one full-screen aggregate display (community voice appears as its Complaints, Feedback and Tip-offs screens) | `situation_room.view` and `situation_room.wall_mode` |
| `GET /situation-room/actions` (+ `POST`, `PATCH /{action}`) | Action Register | `situation_room.view`; changes need `situation_room.manage_actions` |
| `POST /situation-room/exports`, `GET /situation-room/exports/{export}` | Queued institution export | `situation_room.export`; download by the requester only |
| `POST /situation-room/refresh` | Drops the user's cached sections for the current view | `situation_room.view` |

The sidebar shows the group "Executive Situation Room" to users who hold
`situation_room.view`.

## What the overview shows

1. Header: the institution in view and its breadcrumb (ancestors the user may
   open), the period and the previous period it is compared with, *Last
   updated*, Refresh, Wall mode and Action register.
2. Filter bar: institution (searchable, limited to the user's scope), period
   (today, 7 days, 30 days, this month, quarter, year, custom), submission
   type, target (employee or institution), category (including
   *Uncategorized*) and status.
3. KPIs: feedback, suggestions, complaints, unresolved, resolved and unrouted.
   Each KPI shows the change against the previous period. No percentage is shown
   when the previous period had none.
4. Community feedback trend: three lines per day, week or month.
5. Attention required: rule-based items only (see below).
6. Management summary: fixed sentences built from the figures. No language
   model is involved.
7. Comparison by institution: one stacked bar per direct child in the
   published hierarchy (Top 10 plus "Other"), and a table of all of them. The
   institution's own submissions appear as a separate row.
8. Breakdowns: category, the services named most often, where submissions
   stand, employee versus institution targets, and feedback ratings
   (including the positive voice).
9. Employee-targeted link, shown only with both grants.
10. Supporting indicators, collapsed: workforce, performance agreements,
    daily work, field work, cafeteria and participation gaps (institutions
    without an active feedback QR or a public phone). Each card appears only
    with that module's own view permission.
11. Exports.

KPIs and the trend arrive with the page. Comparison, attention and summary
arrive next, then breakdowns and supporting indicators (Inertia deferred
props), so a slow section never delays the first paint.

## Attention rules

The Attention Center never reads complaint text. Every item comes from a
written rule over statuses, categories and dates.

| Item | Shown when | Tone |
|---|---|---|
| Unresolved complaints (with the oldest date) | any exist, whatever the period | Information |
| Unrouted submissions | any exist | Information |
| Open longer than the response time, per type | `sla_days_<type>` is set and some are older | Rule reached |
| Increase per type, increase per category | `spike_threshold_percent` is set, the rise is at least `spike_minimum_count`, and the previous period had at least one | Rule reached |
| Repeated submissions (same institution and category, unresolved) | `repeat_threshold_count` is set and reached | Rule reached |

All five rules are **off by default** (null). They are set in *Public
Feedback → Settings → Situation Room rules*. Until a rule is set, nothing is
called overdue, rising or repeated.

## Action Register

The Action Register records a management follow-up against an institution:
a title, details, an owner (a person or an organization unit of that
institution), an optional due date, a priority and a status (open, in
progress, done, cancelled). It keeps an append-only history.

- The institution must be in the actor's scope. A person as owner must have
  that institution in their own scope. A unit as owner must belong to it.
- An action **never** changes a submission's handler, assignment or status.
  The complaint workflow keeps its own records (verified by a test).
- An action can be started from an Attention item ("Record an action"), which
  records the source.

## Decisions taken (from planning)

- **Scope:** everything in the brief except Transport.
- **Resolved / unresolved:**
  - Unresolved = `pending, unrouted, assigned, reviewed, under_review`.
  - Resolved = `resolved, accepted, not_adopted, closed`.
  - `hidden` is excluded from every count.
  - This is the same set as `PublicFeedbackMetrics::OPEN`.
- **Roll-up:** the same published-hierarchy closure that decides access.
- **Privacy:**
  - There are new `situation_room.*` permissions.
  - Employee-targeted rows also need `service_feedback.view_employee_target`.
  - Submission text needs `situation_room.view_detail`.
  - Submitter identity and attachments are never shown here. The submission's
    own page applies its existing grants.
  - No role receives the new permissions by migration.

## NEEDS_DECISION

| # | Question | Current behaviour |
|---|---|---|
| D1 | Which roles get the six `situation_room.*` permissions (city executive, bureau head, sub-city, woreda)? | None. Super Admin passes through `Gate::before`. |
| D2 | Response times per type (`sla_days_feedback/suggestion/complaint`) | Off |
| D3 | What counts as an increase (`spike_threshold_percent`, `spike_minimum_count`) | Off |
| D4 | What counts as repeated (`repeat_threshold_count`) | Off |
| D5 | Severity grading (critical, high) for attention items | Not graded: "Information" or "Rule reached" only |
| D6 | Whether trend buckets follow Ethiopian months | Gregorian day, week or month buckets, labelled in the user's calendar |
| D7 | Whether past periods should be shown in the hierarchy as it was then | The current published hierarchy, by submission-time institution |
| D8 | Whether the Wall should run under a dedicated display account (kiosk) with no session timeout | Normal user session; the session timeout applies |
| D9 | Retention period for Situation Room exports | Same as submission report exports (kept until cleaned up) |

## NEEDS_DATA

| # | Missing data | Effect |
|---|---|---|
| N1 | Number of services delivered per institution or service | No rates (complaints per 1,000 services); counts only |
| N2 | Submissions recorded at institutions that are not in the published hierarchy | Counted at that institution only. City-wide they show as "recorded outside the published hierarchy". |
| N3 | Public contact phone for each institution | Shown as a participation gap |
| N4 | Realistic production volume for load claims | Measured on 22,347 submissions and 240 institutions only (see performance doc) |

## Files

- Services: `app/Services/SituationRoom/`
  - `SituationRoomMetrics`, `SituationRoomScope`, `SituationRoomFilters`
  - `CommunityVoiceQuery`, `AttentionCenter`, `ManagementSummary`
  - `EmployeeTargetedQuery`, `SupportingIndicators`
  - `SituationRoomService`, `SituationRoomActionService`
- Date buckets: `app/Support/Database/SqlDateBucket.php`
- Controllers:
  - `app/Http/Controllers/Web/SituationRoomController.php`
  - `app/Http/Controllers/Web/SituationRoomActionController.php`
- Export job: `app/Jobs/ExportSituationRoomReport.php`
- Models: `app/Models/SituationRoomAction.php`, `SituationRoomActionEvent.php`
- Migrations: `2026_10_14_130000` (permissions), `130100` (action tables),
  `130200` (export `report` column)
- Pages: `resources/js/Pages/SituationRoom/{Index,Employees,Employee,Wall,Actions}.tsx`
- Layout: `resources/js/Layouts/WallLayout.tsx`
- Components: `resources/js/Components/situationRoom/`
- Charts: `MultiSeriesLineChart` and `StackedBarChart` in
  `Components/dashboard/charts`
- Translations:
  - `resources/js/i18n/{en,am}/situationRoom.ts`
  - `lang/{en,am}/situation-room.php`
- Tests: `tests/Feature/SituationRoom/SituationRoomTest.php`
