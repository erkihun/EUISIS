# Executive Situation Room — Architecture

The Executive Situation Room (የአመራር ሁኔታ መከታተያ ማዕከል) gives leadership live
aggregates across the application, confined to each viewer's organization
scope. It reads; it never writes to a source record. The only table it owns
is the Executive Action Register (`situation_actions`).

## Request flow

```
route (can:situation_room.view [+ category grant])
  └─ SituationRoomController / SituationReportController / SituationActionController
       └─ SituationRoomService::context()          viewer + filters → SituationContext
            ├─ SituationFilters::fromRequest()     whitelisted, shape-checked filters
            └─ SituationScopeResolver::resolve()   OrganizationScopeService → SituationScope
                                                   (403 if the focus is outside the scope)
       └─ SituationRoomService::category()         SituationCache (scope + filters + grants)
            └─ <Category>Analytics::panels()       one closure per panel
                 └─ run() isolates each panel      exception → one ERROR card, not cached
```

The page frame (filters, scope trail, category tabs) is rendered immediately;
the figures are an Inertia **deferred prop** (`result`) fetched right after
first paint.

## Building blocks (`app/Services/SituationRoom`)

| Class | Role |
| --- | --- |
| `SituationCategory` | The category registry: order, grant, launcher section, icon, wall eligibility. The launcher, routes, controller and tests read it. Transport is deliberately absent. |
| `SituationPermissions` | Grant names; `DETAIL_GRANTS` feed the cache key. |
| `SituationScope` / `SituationScopeResolver` | The organizations a query is confined to, from `OrganizationScopeService`, intersected with the focus subtree on the published hierarchy. Applied **before** any aggregation (`SituationScope::apply`). |
| `OrganizationRollup` | Groups per-organization counts into the focus's own office plus one row per direct child (each child includes its subtree). Rows in name order. |
| `SituationPeriod` / `SituationFilters` | Period presets (today, week, month, quarter, year, custom ≤ 10 years), granularity, previous period; whitelisted filters. |
| `SituationPanel` | The panel contract: `kpis`, `bar`, `hbar`, `stacked`, `line`, `donut`, `table`, `notice` (with a reason). |
| `SituationCache` | Short-lived cache keyed by part + scope fingerprint + filter fingerprint + detail grants + the viewer's employee id. Every entry records `generated_at`; a payload with a failed panel is never stored. |
| `SituationSettings` | Typed reader of the `situation_room` settings group. |
| `SituationLabels` | One EN/AM dictionary (`lang/{en,am}/situation_room.php`) for pages and exports. |
| `SituationExportService` | Builds CSV/XLSX/PDF documents from the same payload the screen shows. |
| `Analytics/*` | One class per category; shared base queries in `WorkforceQueries`, `PublicVoiceAnalytics::base()`, `DailyOperationsAnalytics::base()`, `FieldWorkAnalytics::base()`. |

## Categories

Executive Overview · Workforce Composition & Staffing · Organization Structure ·
Service Delivery · Public Complaints · Public Feedback · Tip-offs · Daily
Operations · Performance (EPMS) · Employee Movement · Field Work · Cafeteria ·
Grievance, Discipline & Appeal · Critical Attention · Trends & Comparison ·
Data Quality. Executive Reports, Wall Mode and the Action Register are pages of
their own. See `situation-room-metrics-catalog.md` for every figure.

## Frontend (`resources/js`)

* `Pages/SituationRoom/{Index,Category,Wall,Reports}.tsx`, `Pages/SituationRoom/Actions/{Index,Form}.tsx`.
* `Components/SituationRoom/Panels.tsx` renders any panel; `charts/SituationChart*.tsx`
  lazy-loads the existing chart library (Recharts) — no second chart library.
* `useSituationLabels` resolves keys and bilingual names in the visitor's language.
* Filters live in the URL query; nothing about permissions or data is kept in
  browser storage.
* Sidebar: a standalone "Situation Room" category (launcher, Wall Mode,
  Executive Reports), each entry permission-gated.

## Data changes

`2026_10_14_300000_create_situation_room.php` (additive only): registers the
`situation_room.*` permissions and grants them to the four city-wide admin
roles; creates `situation_actions` (guarded `down()`: never dropped while it
has rows); adds index `esf_org_type_submitted_idx`. No existing row changes.
