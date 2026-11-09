# Admin: Public Feedback workflow

One status vocabulary, a workflow per type (`PublicFeedbackWorkflow`). No
status was added or renamed in this phase.

## 1. Transitions

| Type | From | To |
|---|---|---|
| Feedback | pending | reviewed, resolved |
| | reviewed | resolved, closed |
| | resolved | closed |
| Tip-off | pending | assigned, unrouted, under_review |
| | unrouted | assigned, under_review |
| | assigned | under_review |
| | under_review | accepted, not_adopted |
| | accepted, not_adopted, reviewed, resolved | closed |
| Complaint | pending | assigned, unrouted |
| | unrouted | assigned |
| | assigned | under_review |
| | under_review | resolved |
| | resolved, reviewed | closed |

`pending → assigned | unrouted` is system-managed (routing at submission, or
an assignment). Staff use the other moves, each behind its grant
([admin-public-feedback-access-control.md](admin-public-feedback-access-control.md)).
Any other move is refused with "This status change is not allowed" (422),
and nothing changes.

Every transition, in one transaction:

1. sets the status and stamps `assigned_at`, `acknowledged_at` (reviewed,
   under review), `resolved_at` (any outcome) or `closed_at`;
2. appends a `public_feedback_status_history` row (from, to, actor, reason,
   time), which is never edited;
3. writes an audit record (`ServiceFeedbackStatusChanged`);
4. on an outcome, queues the resolution notice **after commit**.

### Fixed in this phase: Feedback

The Feedback review used to write `status` directly. It accepted "reviewed"
or "resolved" from any status (including closed or hidden), wrote no
history and set no `resolved_at`, so Feedback was missing from "days to
outcome". It now goes through the workflow above, with a **Close** step
(`service_feedback.close`). The review note is kept on the record and in the
history row. The detail page offers only the moves allowed from the current
status and shows the full history.

**Hide / restore** (moderation, Feedback and Tip-offs) sits outside the
workflow, but is now recorded in the history (`x → hidden`, `hidden → x`).
Restoring returns the entry to the status it had before it was hidden;
before, it always went back to "pending".

## 2. Routing and assignment

- At submission: the first matching routing rule assigns a handler (a user,
  an organization unit, or a role of the target institution). If none
  matches, the submission becomes **unrouted**. It is never refused or
  dropped.
- `POST /service-submissions/{type}/{id}/assign` (`service_feedback.assign`,
  or `.reassign` once a handler exists): ends the open assignment, opens a
  new one with name snapshots, and records the reason. From pending or
  unrouted the status becomes assigned.
- The handler must belong to the target institution
  (`PublicFeedbackAssignmentService`). The options offered are that
  institution's users, units and roles.
- **Target ≠ handler.** Assignment never touches the target fields (tested).

## 3. Resolution, closing, reopening

- Resolution needs no extra field. The reason box is optional for every
  type. A required resolution summary would be a policy change
  (NEEDS_POLICY_DECISION).
- Closing stamps `closed_at` and is final: there is no reopen transition in
  the approved workflow. Reopening is NEEDS_POLICY_DECISION. If it is
  approved, it must append history, never rewrite `closed_at`.

## 4. Boundaries

- A public Complaint is not an employee grievance, a disciplinary case or an
  appeal. A **referral** to discipline (`service_feedback.refer_discipline`)
  creates its own `public_feedback_referrals` record, and the decision links
  a separate disciplinary case. The Complaint itself is never converted. The
  employee a complaint is about, or names, can never act on it.
- Volume is not guilt: nothing in this workflow writes to EPMS, employee
  scores, salary, position or employment status.

## 5. Notifications

`PublicFeedbackNotifier` queues internal notices (unrouted, assigned,
resolved, referred) and the submitter's acknowledgement at submission, all
with `DB::afterCommit`. A failing mail or SMS provider cannot roll back a
committed transition (tested with a failing mail transport).

- Internal notices carry the type, the reference and a link: never the text
  or the submitter.
- The submitter's acknowledgement carries the reference and the time: never
  a handler, a note or the outcome.
- No notice to the submitter on resolution is built. Whether to send one is
  NEEDS_POLICY_DECISION.
