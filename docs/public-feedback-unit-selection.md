# Public Feedback: Relevant Work Unit

How the organization public feedback form (Feedback, Tip-off/Suggestion,
Complaint) lets a visitor say **which part of the institution** their
submission concerns, and how that answer travels through routing, the staff
screens, the Situation Room and the reports. Companion to
[organization-public-feedback-form.md](organization-public-feedback-form.md),
[public-feedback-routing.md](public-feedback-routing.md) and
[admin-public-feedback-management.md](admin-public-feedback-management.md).

## 0. Two questions, two fields

| Question | Field | Definition |
|---|---|---|
| **Where** does the issue belong? | **Relevant Work Unit** (`organization_unit_id` + snapshots, or General / Not sure) | *Where the issue belongs*: the part of the institution the submission concerns. |

The form asks it as its own fieldset on the first step, with its own helper
text. It is neither the target organization (the token) nor the routing
handler. The issue category that used to follow it was removed on 2026-10-27;
submissions carry no category.

## 1. Three things that are never the same

| | Set by | Stored in | Can change later? |
|---|---|---|---|
| **Target organization** | The scanned QR / opaque token, resolved on the server on every GET and POST | `organization_target_id`, `organization_id`, `target_name_snapshot` | Never. Nothing the browser posts can move it. |
| **Relevant work unit** | The visitor, from the units the server offered for that token, or "General / Not sure" | `organization_unit_id` (nullable) + `organization_unit_name_snapshot`, `organization_unit_name_am_snapshot`, `organization_unit_type_snapshot`, `unit_selection_mode` (`SELECTED` / `GENERAL`) | Never. It is what the visitor said; a later rename or archive of the unit does not rewrite it. |
| **Routing handler** | Routing rules at submission, or staff by hand | `public_feedback_assignments` | Yes — reassignment ends one row and opens another. |

The relevant unit is *contextual attribution*: it says what the submission is
about. It is not a new target type, it is not the handler, and it is not a
finding. A unit's complaint count is a count of what the public said, never a
measure of the unit or its staff (no discipline, no EPMS, no ranking).

## 2. Which units the public may choose

`PublicOrganizationServiceContext::feedbackUnitOptions()` /
`availableFeedbackUnit()`: the resolved institution's own
`organization_units` that are

- `is_public_feedback_selectable = true` (admin flag on the unit form,
  "Available for public feedback"),
- `status = active`, not soft-deleted,
- inside their `effective_from` / `effective_to` window today.

The migration flags existing `office` units and the units that replaced a
former institution office; everything else (directorates, teams, internal
units) is opt-in. New `office` / institution-office units default to
selectable (`OrganizationUnit::booted()`).

The page receives, per unit, only `id`, `name` (visitor's language), `type`,
`parent_id` and `parent` (the parent's name, when that parent is itself
offered — so the select can group "Permits desk" under "Head office"). No
code, no manager, no staff, no counts. At most `UNIT_LIST_LIMIT` (200) units
travel with the page; the form adds a filter box above 12.

### Server re-validation (never trust the browser)

On POST, `PublicServiceFeedbackController` re-resolves the token, then:

- `organization_unit_id` must pass `availableFeedbackUnit(unit, resolvedOrg)`:
  another institution's unit, a draft / inactive / archived / deleted unit, a
  unit outside its window, or one not flagged public → `relevant_unit_invalid`
  (EN/AM), nothing stored.
- While the institution offers at least one unit, a Tip-off or Complaint must
  answer: a unit, or `unit_selection_mode = GENERAL`. `SELECTED` with no unit →
  `relevant_unit_required`. A post with no `unit_selection_mode` key at all
  (a page opened before this field) is accepted as `GENERAL`, so a rolling
  deployment never blocks a visitor. Feedback (ratings) may stay general.
- An institution with no selectable unit asks nothing and blocks nothing.
- On the employee QR routes `organization_unit_id` and `unit_selection_mode`
  are `prohibited`: there the unit is the employee's own post.

## 3. "General / Not sure"

Stored as `organization_unit_id = NULL`, `unit_selection_mode = 'GENERAL'`.
There is **no** "General" row in `organization_units`; every screen spells
the answer out from the mode (`generalNotSure` / `export_values.unit_selection.GENERAL`).

## 4. Routing

`public_feedback_routing_rules.organization_unit_id` (nullable, FK
`pfrr_unit_fk`, index `pfrr_unit_idx`) narrows a rule to one relevant unit.

- Candidate rules match `organization_unit_id IS NULL OR = submission.unit`;
  a GENERAL submission therefore matches only unit-less rules.
- Precedence: institution, then **named unit**, then target type, submission
  type, tip type, category, priority, age.
- A unit is accepted only on its own institution's rule (`unit_out_of_scope`),
  never on a city-wide rule (`prohibited` without `organization_id`).
- The unit the rule matches on and the handler it names are two separate
  fields. A rule may route "about Permits office" to a user, a role or a
  different unit.
- No matching rule → `unrouted`, as before.

## 5. Staff screens, Situation Room, reports

- **All Submissions**: unit shown under the institution from the snapshot
  (GENERAL spelled out); unit filter offered only for the institution chosen
  in the organization filter, and only when that institution is in the user's
  scope. A unit id of another institution returns nothing.
- **Complaint / Tip-off detail and inbox**: "Relevant Work Unit" from the
  snapshot; the Organization-form section (`service_point`) too.
- **Feedback admin list / detail**: unit filter after choosing an
  organization; the detail shows the recorded unit or "General / Not sure".
- **Situation Room › Community Voice**: `CommunityVoiceQuery::byUnit()` —
  "Relevant work units named", counts per unit under the recorded name, one
  "General / Not sure" bucket, inside the same hierarchy scope as every other
  panel; rows older than the field and employee-QR rows are left out. The
  panel carries the boundary note (a count is not a finding).
- **Submission reports**: `PublicFeedbackMetrics::byUnit()` and the export
  column `organization_unit` (snapshot, or "General / Not sure").

## 6. Decisions still needed

| Code | Decision |
|---|---|
| NEEDS_POLICY_DECISION_PUBLIC_FEEDBACK_UNIT_REQUIREDNESS | Today a Tip-off or Complaint must answer unit-or-General while units exist; Feedback may stay general. Confirm, or make Feedback ask too. |
| NEEDS_POLICY_DECISION_PUBLIC_UNIT_HIERARCHY_SOURCE | Organization units are not tied to hierarchy versions; the form uses the structural `parent_unit_id`. Confirm that this is the public-facing structure, or define a published unit structure. |
| NEEDS_POLICY_DECISION | Which internal units (directorates, teams) each institution flags as public-selectable. The default exposes only service offices. |

## 7. Tests

`OrganizationFeedbackFormTest` (acceptance, every refusal case, snapshots on
rename/archive, requiredness, Amharic message, parent labels, no internal
data), `PublicFeedbackRoutingTest` (unit rule, GENERAL fallback, unrouted,
rule validation, reassignment keeps the unit), `AdminPublicFeedbackManagementTest`
(scoped unit filter, snapshot and GENERAL on list and detail),
`SituationRoomTest` (byUnit scope, buckets, reconciliation),
`PublicFeedbackReportsTest` (byUnit, export values EN/AM).
