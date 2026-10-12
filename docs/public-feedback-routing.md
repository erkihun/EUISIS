# Public feedback routing and the four relations

Who a public submission is *about*, who *handles* it, who is *related* to it
and where a *referral* goes are four different relations. This document
fixes their names and rules (QA brief §20–§26, §48–§50, §53–§57).

## 1. Original target — immutable

| | Employee target | Institution target |
| --- | --- | --- |
| Source | employee card QR (`source_type = employee_card_qr`, `employee_feedback_token_id`) | institution poster QR (`source_type = organization_feedback_qr`, `source_organization_qr_id`) |
| Columns | `employee_id`, `employee_assignment_id` (the assignment at scan time), `organization_id` / `organization_unit_id` / `position_id` (context at scan time), `position_service_id` for ratings | `organization_target_id`, `organization_id` (same institution) |
| Snapshots | `target_name_snapshot`, `target_code_snapshot`, service snapshots | `target_name_snapshot`, `target_code_snapshot` |
| Set by | `PublicFeedbackTargetResolver` from the token; the client sends nothing about the target | same |
| Changes after insert | never; no service writes these columns, PostgreSQL CHECKs forbid inconsistent rows | never |

A transfer, a rename or a structure version does not touch a stored
submission: the history keeps the submission-time context, the next scan
carries the current one. Nothing "retargets" a submission; the data-quality
command reports ambiguous rows as NEEDS_DATA_REVIEW and never moves them.

## 2. Internal handler — assignment

`public_feedback_assignments` (one open row per submission, enforced by the
partial unique index `pfa_one_open_per_submission`). `handler_type` is `user`,
`organization_unit` or `role`; `handler_name_snapshot` keeps the name as it
was. Reassignment ends the previous row (`ended_at`, `ended_by`) and inserts
a new one, so the chain of custody survives. The handler is never the public
target and is never shown to the citizen.

### Routing rules (`public_feedback_routing_rules`)

Rating feedback is **not** routed (it keeps the lightweight review flow).
Suggestions and complaints are routed inside the submission transaction:

1. candidate rules = active, in effect, and matching on each dimension that
   the rule names: institution (`organization_id` or city-wide `null`),
   `target_type`, `submission_type`, `category_id`;
2. precedence: a rule naming the institution beats a city-wide one, then a
   named target type, then a named submission type, then a named category;
   among equals the lowest `priority` number, then the older rule;
3. the first candidate that can be applied wins. A candidate is skipped —
   with a warning naming the rule id only — when its handler no longer
   exists, is the target employee, or is outside the submission's
   institution; the next candidate is tried;
4. no applicable rule → status `unrouted`, audit entry naming the skipped
   rule ids, notification to the routing administrators (holders of
   `service_feedback.manage_routing` in scope and the city-wide ones),
   **never** to the target employee. An unrouted submission is assigned by
   hand (`service_feedback.assign`).

Rules are managed under Public Feedback Management → Routing rules: a scoped
administrator manages the rules of their institutions; city-wide rules need
an unrestricted account. A rule that already routed submissions is retired,
not deleted. Rule validation refuses a handler the rule's institution cannot
reach and a category of another institution.

### No-route condition

A deployment with no active rule stores every suggestion and complaint as
`unrouted` and tells only the routing administrators; the citizen still
receives a reference. `production:readiness` warns when no active rule
exists and `feedback:data-quality` lists legacy suggestions and complaints
that were stored before routing existed (repairable: they are routed by
today's rules, never anything else).

## 3. Related employee subject — secondary

An institution-targeted complaint may name employees as *subjects* during
review (`public_feedback_subject_employees`, with a role such as "named by
the complainant" or "identified by review"). A subject must belong to the
submission's institution, cannot be the target of an employee-targeted
complaint, and is never promoted to target: the submission keeps
`target_type = organization`. Subjects are visible only to handlers with
`complaints.review`, and a subject who holds a handler role is treated like
a target for conflict-of-interest purposes (cannot request or decide the
referral).

## 4. Referral destination — separate controlled record

`public_feedback_referrals`: a request (`requested`) by a reviewer with
`refer_discipline`, decided (`accepted` / `declined`) by a different holder
of the permission who is neither target nor subject. The complaint keeps its
type, status, target and history; the referral is a pointer with notes. A
decision does not open a disciplinary case (`disciplinary_case_id` stays
null) — who opens the case, on what evidence and under which procedure, is
NEEDS_DECISION and belongs to the discipline module. Nothing in this flow
changes the employee record, EPMS, employment status or pay.

## 5. What the public sees

The citizen sees the target they scanned (name, code, institution, the
service list for an employee), a reference number for complaints, and the
status words of the public workflow if they return to the receipt in the
same session. They never see the handler, subjects, referrals, internal
notes or other submissions. There is no public tracking page (NEEDS_DECISION).
