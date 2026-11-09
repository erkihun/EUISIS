# ADR 0033: Escalation ladder and deadlines for public Tip-offs and complaints

- Status: accepted
- Date: 2026-11-08
- Owner request: public Tip-offs and complaints should have assignable work units and routes "like employee grievances".

## Context

Tip-offs and complaints already had routing rules (institution, target, type,
Tip-off type, work unit → one handler: user, work unit or role), automatic
assignment, an "unrouted" queue and manual assign/reassign. Unlike employee
grievances (committee → team → directorate) they had a single level: no
deadline and no way up when a handler did not act.

## Decision

1. A routing rule serves one **escalation level** (1–5, default 1) and may set
   **response days** (1–365). New submissions are routed with level 1 rules only.
2. An assignment records its **level** and **due date** (assigned time + the
   rule's response days). A manual hand-over inside the same level keeps the
   level's due date; the deadline belongs to the level, not the person.
3. `service-feedback:escalate-overdue` (every 15 minutes) moves an open case
   (Assigned or Under review) whose due date has passed to the matching rule
   of the next level, with the same matching and fallback order as routing.
   The ended assignment is marked `escalated`.
4. A reviewer with `service_feedback.reassign` may escalate a case by hand,
   with a required reason.
5. At the top configured level an overdue case stays with its handler and is
   counted as overdue on the type's dashboard.

## Decisions taken as defaults (owner may change)

- **No default deadline.** A rule without response days gives no due date and
  never escalates on its own. No legal response period is assumed; institutions
  enter their own days per rule.
- Calendar days, not working days (no holiday calendar is applied here).
- Five levels at most; the ladder's meaning (for example unit → directorate →
  head of institution) is whatever the institution's rules say.
- Rating feedback is not routed and has no ladder.

## Consequences

- Existing rules become level 1 with no deadline: behaviour is unchanged until
  someone sets response days or adds a level 2 rule.
- Columns: `public_feedback_routing_rules.escalation_level`, `response_days`;
  `public_feedback_assignments.level`, `due_at`, `escalated`
  (migration `2026_11_08_160000_add_public_case_escalation_levels`).
