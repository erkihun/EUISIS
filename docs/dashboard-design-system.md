# Dashboard Design System

How dashboard analytics are built in this codebase. This documents the system
that exists, so a new chart joins it instead of inventing a second visual
language.

Charts sit inside the standardised Admin UI ([ui-design-system.md](ui-design-system.md),
[admin-ui-consistency.md](admin-ui-consistency.md)) and introduce no components of
their own beyond what is listed here.

## Chart library

**Recharts** (`recharts@3.8.1`) is the only chart library. Do not add Chart.js,
ApexCharts, ECharts, or Highcharts — one chart system, one set of behaviours, one
bundle.

The library is ~300 KB, so it is **never** in the page chunk. Every chart body
lives in a module that is `lazy()`-imported behind a `<Suspense>` with a
same-height `ChartPlaceholder`, so the card, its title and its empty state paint
immediately and only the plot waits. See `charts/SimpleCharts.tsx` (wrapper) and
`charts/SimpleChartsImpl.tsx` (the only module importing `recharts`).

Keep that split when adding a chart. A chart imported directly into a page puts
300 KB back on first paint.

## Components

| Component | Use |
| --- | --- |
| `ChartCard` | The shell around every visualization: title, optional description, optional action, body, optional footer |
| `KpiCard` | A single headline metric with optional trend and drill-through |
| `StatTile` | A compact number inside a section, below the headline strip |
| `SimpleBarChart` | Category comparison |
| `SimpleLineChart` | Time series |
| `StatusDistribution` | Donut + legend list for status composition |
| `CardLifecycleFunnel` | Ordered stage progression |
| `ProviderRanking` | Top-N horizontal ranking |
| `WorkflowQueue` | Actionable pending items (a list, not a chart) |
| `AlertPanel` | Exceptions needing attention |
| `RecentActivityFeed` | Recent actionable activity |
| `EmptyDashboardState` | Shown in place of a chart with no data |
| `DashboardSection` / `DashboardHeader` / `DashboardTabs` | Page structure |

Create a new component only when none of these fit. Prefer a new `ChartCard`
body over a new shell.

## Chart type by question

| Question | Chart |
| --- | --- |
| How did this change over time? | Line (`SimpleLineChart`) |
| How do these categories compare? | Bar (`SimpleBarChart`) |
| Which are the biggest? | Top-N ranking (`ProviderRanking`) |
| What is this made of? | Donut (`StatusDistribution`) — **2–5 categories only** |
| How far through the stages? | Funnel (`CardLifecycleFunnel`) |
| What are the exact values? | A table, not a chart |
| What needs action? | `WorkflowQueue` / `AlertPanel`, not a chart |

**Donut rule.** Only when the categories form a meaningful whole and there are
roughly 2–5 of them. Status fields qualify. Anything with many categories is a
bar chart. There are no 15-slice pies in this codebase; do not add one.

**No 3D, no perspective, no gradient fills, no decorative gauges.** Verified
absent as of this document. Magnitude must be readable from length or angle
alone, never from a visual effect. Clarity is the whole job here.

## Colour

`useChartColors()` is the single source of chart colour. It returns `primary`,
`accent`, `grid`, and a `series` array, re-reading when the `dark` class on
`<html>` changes via a `MutationObserver`.

The palette is deliberately restrained — a blue/orange pair plus tints, not a
rainbow. Do not hardcode a hex value in a chart; take it from the hook so dark
mode and future theming keep working.

Everything outside the plot uses CSS custom properties: `--app-surface`,
`--app-border`, `--app-foreground`, `--app-muted-foreground`. These flip with the
theme automatically.

**Never rely on colour alone.** `StatusDistribution` pairs every slice with a
labelled legend row carrying the value, so the information survives greyscale and
colour blindness.

## Dark mode

Both themes are first-class. Axis text, gridlines, tooltips, legends, card
surfaces, borders and hover states all resolve from the hook or from
`--app-*` tokens.

When adding a chart, check it in both themes before shipping. A hardcoded
`#333` is invisible in one of them.

## Numbers

Format through `formatNumber` / `useDisplayFormat` (`utils/displayFormat.ts`).
Never render a raw `3000000`.

- Axis and card values: grouped (`3,000,000`), abbreviated where space demands.
- Tooltips: may show the precise value.
- Percentages: one consistent form per concept. Do not mix `0.724`, `72.4` and
  `72.4%` for the same measure.
- `tabular-nums` on changing figures so digits do not jitter.

## Zero vs no data

These are different and must look different:

- **0** — a real measured zero. Render the number.
- **No data** — nothing in range. Render `EmptyDashboardState`.

`EmptyDashboardState` always shows a message; a missing `title` falls back
rather than rendering a blank gap. Pass a localised `emptyTitle`
(`t('dashboard.noData')`) so the fallback is never the visible path.

An empty state is **one short line**, not a chart-sized dashed box — a quiet
week should not turn the dashboard into empty rectangles.

## Tone

`KpiCard` tone describes the **value**, not the category. A metric that would
normally be amber ("Pending Requests") goes neutral at zero, because an empty
queue is good news. Without this, a clear desk lights the dashboard with
alarm-coloured zeros and the reader learns to ignore colour entirely.

## KPI discipline

`HEADLINE_KPI_LIMIT = 6`. The top strip carries only institution-wide metrics
that deserve a daily glance; everything else keeps its number but moves down to
the section it belongs to, beside the chart that explains it, via
`group`. Nothing is dropped, and nothing is promoted for decoration.

A KPI's `trend` must measure the same thing as its value, or say what it
measures instead. A flow delta under a running total carries its own label (see
`employeeTrend()`), because "+42 vs previous period" under "3,000,000" tells the
reader nothing true.

## Loading

Per-chart, never one page-wide spinner. The card and its title render from the
page chunk; the plot arrives into a fixed-height `ChartPlaceholder` so nothing
shifts. Headline KPIs are plain numbers in the Inertia payload and need no
chart library at all, so they are readable first.

## Grid and height

The chart grid is `xl:grid-cols-2`, with full-width for a primary chart. Cards
align; widths are not chosen per chart.

Heights are fixed per visualization class — 240 px for bar/line, 256 px for the
donut. Pick an existing height rather than a new one; arbitrary values make a
page of cards look misaligned.

Charts are always inside `ResponsiveContainer width="100%"`. The only fixed
pixel width in a chart is the Y-axis label gutter (`width={36}`), which is
correct. A fixed plot width causes horizontal page overflow on mobile.

## Responsive

Cards stack on narrow screens; the chart grid collapses to one column. Mobile
prioritises KPIs and primary trends, not a scaled-down desktop analytics grid.
`min-w-0` and `overflow-hidden` on the card prevent a long label from pushing the
layout wide.

Long category labels take a horizontal bar chart or wrapping — not a blanket
90-degree rotation.

## Accessibility

- `ChartCard` is a `<section aria-labelledby>` bound to its own title, so the
  chart is announced with its name.
- Decorative icons are `aria-hidden`.
- Values appear as text in legends and tiles, not only on hover. **Nothing
  important may be hover-only.**
- Controls are real focusable elements.
- Contrast comes from the token system in both themes.

## Amharic

Every label routes through `t()` with keys in `i18n/en/dashboard.ts` and
`i18n/am/dashboard.ts`. Both files must stay in sync — `npm run i18n:audit
--strict` enforces it and is part of the verification run.

Use the approved terms in [amharic-terminology-guide.md](amharic-terminology-guide.md)
and the spelling variants the audit checks (ሠራተኛ not ሰራተኛ, ሥራ not ስራ, and so
on). Do not invent a translation for a term the guide already settles.

Amharic strings run longer than English. Check titles, axis labels, legends and
filters at mobile width.

## Drill-down

A KPI with an `href` is clickable and navigates to the filtered list; a KPI
without one is not. Only emit an `href` when the destination exists and the
viewer's permission already allowed the metric.

Do not make a bar or counter *look* clickable when no action exists.

## Filters and scope

One filter bar per dashboard, resolved server-side by `DashboardScopeService`:
organization, date preset (`today`, `7d`, `30d`, `90d`, `custom`), and the
previous comparison window.

Scope is applied **in SQL before aggregation**, never by filtering a city-wide
result in PHP. See [dashboard-metrics-catalog.md](dashboard-metrics-catalog.md).

## Freshness

Figures are cached ~120 s per scope/filter combination, so they are
near-real-time, not live. The header shows a refresh time. Do not present cached
aggregates as second-by-second data.

## What not to build

- A chart that answers no operational or management question.
- A metric invented to fill a grid cell.
- A target line when no target is configured.
- Public complaint counts as an employee performance ranking.
- Chart-image export (formal reports go through the report/export services; a
  dashboard screenshot is not an official report).
