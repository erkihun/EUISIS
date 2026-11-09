# Situation Room — Community Voice: what is counted

The **Community Voice by Institution** view (`/situation-room/community-voice`,
grants `situation_room.view` + `situation_room.public_voice.view`) counts
**public submissions**: rows of `employee_service_feedback` made through an
employee card QR or an institution feedback QR. It counts nothing else. The
room's other categories read other modules and are described in
[situation-room-metrics-catalog.md](situation-room-metrics-catalog.md).

## Domain boundaries

| Domain | In the Situation Room? |
|---|---|
| Public feedback, suggestions and complaints (`employee_service_feedback`) | Yes: the only source of the community-voice figures |
| Employee grievances (Regulation 201) | No. A grievance is an employee's case, not a public submission. Counted only in the separate *Grievance, Discipline & Appeal* category (counts only). |
| Discipline cases | No (separate category, counts only, small groups protected) |
| Appeals | No (separate category, counts only) |
| Performance (EPMS) | Shown only as a separate supporting card (agreement counts), never mixed into community-voice figures |
| Transport | Out of scope |

## What one submission contributes

| Field | Use |
|---|---|
| `type` | feedback, suggestion or complaint |
| `target_type` | employee or organization. A legacy `NULL` is an employee target. |
| `status` | Resolution bucket (below) |
| `submitted_at` | The period filter and the trend. `created_at` is not used. |
| `organization_id` | The institution the submission was recorded at, captured when it was submitted. Used for scope and roll-up for both target types. |
| `category_id` | Category breakdown. `NULL` is shown as *Uncategorized*. |
| `position_service_id` + `service_no_snapshot` / `service_name_snapshot` | Services named most often |
| `organization_unit_id` + `organization_unit_name_snapshot` / `…_am_snapshot`, `unit_selection_mode` | Relevant work units named (organization form only). Counted under the name recorded at submission; `GENERAL` is its own bucket; rows with neither are not counted. A count is what the public said a submission concerns, never a finding about the unit. |
| `employee_id` | Employee-targeted counts |
| `rating` | Feedback ratings (1–5) |

The **subject and message** are never aggregated. They appear only on the
employee drill-down, and only with `situation_room.view_detail`. **Submitter
identity and attachments** never appear in the Situation Room.

## Resolution buckets

| Bucket | Statuses |
|---|---|
| Unresolved | `pending`, `unrouted`, `assigned`, `reviewed`, `under_review` |
| Resolved | `resolved`, `accepted`, `not_adopted`, `closed` |
| Excluded | `hidden` (removed from every count) |

The status chart groups the statuses as follows:

- pending
- unrouted
- assigned
- under review (reviewed + under_review)
- resolved (resolved + accepted + not_adopted)
- closed

## Visibility applied before counting

Every query starts from these filters, in this order:

1. **Target type grants.** Employee-targeted rows need
   `service_feedback.view_employee_target`. Institution-targeted rows need
   `service_feedback.view_organization_target`. A user with neither sees zero.
2. **Own submissions excluded.** Rows about the viewer's own employee record
   are left out, as everywhere else in public feedback.
3. **Hidden excluded.**
4. **Organization scope and roll-up** ([situation-room-hierarchy-rollup.md](situation-room-hierarchy-rollup.md)).
5. **The filters** the user chose.

## Language

- A count is what the public reported, not a finding. Pages say "submissions
  about", "unresolved", "more than the previous period". They never say
  "worst", "most complained-about employee" or similar.
- There are no ranking titles. The employee list is ordered by the most
  recent submission by default. Ordering by count is an explicit choice.
- There are no photos.
- An increase is reported only when a configured rule says so, and only
  against a non-zero previous period.
- Amharic follows `resources/data/amharic-terminology.json`:
  - ተቋም for institution
  - የሥራ ክፍል for organization unit
  - ያልተላለፈ for unrouted
  - የመዳረሻ መብት for permission (never ፈቃድ)
  - "ጉዳዩን ያቀረበው ሰው" for submitter

## Employee-targeted counts

- Counted per `employee_id`, inside the institution in view. They are placed
  by the submission-time institution.
- A transferred employee's earlier submissions stay with the institution they
  served then. The drill-down shows "Recorded at" for each submission.
- A leader who has no submission about an employee in their scope gets a 404
  for that employee, so they cannot learn the name.

## Tip-offs and the category views

Suggestions are presented to the public as **Tip-offs (ጥቆማ)** with two types,
Corruption and Improper practice (`tip_type`). The *Tip-offs* category of the
room breaks them down by type; Tip-offs stored before types existed are shown
as *Unclassified (older)* and never guessed, so the buckets always add up.
The *Public Complaints*, *Public Feedback* and *Tip-offs* categories use the
same base rows as this view (moderation-hidden excluded, `organization_id`
snapshot for scope, `submitted_at` for the period) — see
[situation-room-public-voice-analytics.md](situation-room-public-voice-analytics.md).
