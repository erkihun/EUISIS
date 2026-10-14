# Community voice analytics — Tip-offs

Amharic: የሕዝብ ድምፅ ትንተና — ጥቆማዎች

> **Scope note.** No "Executive Situation Room" module exists in this
> repository. Tip-off analytics were added to the two existing surfaces: the
> main **Dashboard**, which has a wall-display layout, and **Public submission
> reports** (`/feedback-admin/submission-reports`). If a dedicated Situation
> Room is built, it should reuse `PublicFeedbackMetrics::tipOffs()` and
> `tipOffsByOrganization()` rather than re-count.

## Rules for every Tip-off figure

- They are counts of **reports received**, not findings. Pages carry the note
  "These are reports received, not findings."
- Nothing is ranked, scored or labelled as conduct. There is no "most
  corrupt" anything. Tables are in name order, never sorted by count.
- The three buckets always add up to the total: `CORRUPTION` +
  `IMPROPER_PRACTICE` + `UNCLASSIFIED_LEGACY`, where the last holds
  suggestions stored before Tip-off types existed, never guessed.
- No tipster identity, narrative or evidence appears in any aggregate. A test
  asserts the tipster's name, phone, e-mail and narrative are absent from the
  dashboard and reports pages.
- Tip-off counts never feed EPMS or performance scores.

## Dashboard (wall-display safe)

Tiles: **Tip-offs received**, **Corruption Tip-offs**, **Improper Practice
Tip-offs**, **Tip-offs awaiting routing**. These are counts in the viewer's
organization scope (`DashboardMetricService::tipOffQuery`). Each tile links to
the Tip-off inbox, filtered.

## Public submission reports

- **Tip-off Type** filter: All, Corruption, Improper Practice, Unclassified.
- **Tip-offs received**: total and per type.
- **Tip-offs by institution**: per type, in institution name order.
- **Employee-targeted Tip-offs**: shown only once one institution is selected,
  only to users who may see employee targets, in employee name order. Never
  city-wide.
- Exports add `tip_type` and `intake_channel`. Tipster columns (name, phone,
  e-mail, organization, address) appear only with the identity grant.

## Inbox

The Tip-off inbox (`/service-suggestions`) filters by type and shows each
row's type, with Unclassified for legacy rows.
