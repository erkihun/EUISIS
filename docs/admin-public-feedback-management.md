# Admin: Public Feedback Management

How staff find, read and act on public submissions (Feedback, Tip-offs and
Complaints) about employees and about institutions. The domain is
unchanged: one table (`employee_service_feedback`), one workflow
(`PublicFeedbackWorkflow`), one scope rule. This document maps the admin
screens onto it.

Related: [admin-public-feedback-access-control.md](admin-public-feedback-access-control.md),
[admin-public-feedback-workflow.md](admin-public-feedback-workflow.md),
[admin-public-feedback-uat.md](admin-public-feedback-uat.md),
[admin-public-feedback-production-readiness.md](admin-public-feedback-production-readiness.md),
[organization-public-feedback-form.md](organization-public-feedback-form.md).

Naming: the internal type `suggestion` is the **Tip-off** (ጥቆማ). Admin
screens say "Tip-off"; only the database and the URLs say `suggestion`.

## 1. Sidebar: Public Feedback Management

| Item | Route | Shown with |
|---|---|---|
| Dashboard | `service-feedback.admin.dashboard` | `service_feedback.view` |
| **All Submissions** | `service-submissions.index` (`/service-submissions`) | `service_feedback.view` or `service_feedback.complaints.view` |
| **Unrouted** | `service-submissions.unrouted` (`/service-submissions/unrouted`) | either of the two |
| Client Feedback | `service-feedback.admin.index` | `service_feedback.view` |
| Tip-offs | `service-submissions.suggestions` | `service_feedback.view` |
| Complaints | `service-submissions.complaints` | `service_feedback.complaints.view` |
| Categories, Questionnaire, Routing rules, Organization QR codes, Reports, Submission reports, Settings | existing | their own permissions |

The sidebar only hides links. Every route checks its permission and scope
again on the server.

## 2. All Submissions (new)

`PublicSubmissionListController`. Every type and both target types in one
server-side paginated list (25 per page). Its base query is
`PublicFeedbackMetrics::query()`, the same one the dashboard, reports and
export use, so the list, the cards and the export always agree.

- **Columns:** reference (or the type for Feedback, which has no reference),
  type, target (Employee/Institution badge, name, employee number or
  institution code), organization and unit, category, status, submitted
  (Gregorian in English, Ethiopian in Amharic), current handler.
  The list never carries the submitter's identity or the narrative.
- **Cards:** All, Feedback, Tip-off, Complaint, Open (pending, unrouted,
  assigned, under review), Outcome recorded (resolved, accepted, not
  adopted), Unrouted. The cards use every filter except type and status, so
  they split the same slice. Their parts add up to "All" (tested).
- **Filters:** type, target type, organization, relevant work unit
  (`organization_unit_id`; its options are the chosen organization's units
  and appear only once an organization in scope is chosen — a unit id of
  another institution returns nothing), category, status, submitter identity
  mode, date range (on `submitted_at`), handler (`handler_type`,
  `handler_id`), "My queue". Each one only narrows the scoped base.
- **Unit column:** the relevant work unit as recorded at submission
  (`organization_unit_name_snapshot`, Amharic snapshot in Amharic), or
  "General / Not sure" when the visitor chose that. A later rename of the
  unit does not change old rows.
- **Search** (`q`): an exact reference match (unique index), or a
  contains-match on the target name, service, institution, category and,
  with the employee-target grant, the employee's name. It runs inside the
  scoped, filtered slice. The narrative and the submitter are never
  searched: a match would reveal who sent what.
- **Sort:** newest (default), oldest, status, institution, category. Server
  side, by sub-select.
- **Empty states:** "No public submissions yet", "No results for these
  filters", "Nothing is waiting to be routed".
- Each row opens its type's own detail page (§3), which checks access again.

## 3. Detail pages

| Type | Page | Actions |
|---|---|---|
| Feedback | `ServiceFeedback/Show` (`/feedback-admin/{id}`) | review → resolve → close (workflow), hide/restore, delete |
| Tip-off, Complaint | `ServiceFeedback/SubmissionDetail` (`/service-submissions/{type}/{id}`) | assign/reassign, workflow transition, name employees (Complaint), discipline referral request/decision |

Both pages show:

- **The target:** an employee (name, number, unit, position) or an institution
  (logo, name, code, service point). No database id is shown.
- **Organization context and category.**
- **The service as recorded:** the employee's position service, or the
  institution's catalog service, or the visitor's own words, flagged as not
  linked to the catalog.
- **The organization form's questionnaire answers,** in the wording of the
  version the visitor answered (`PublicFeedbackQuestionnaire::presentAnswers`).
  Ratings show as "4/5 · Satisfied", choices by their label, yes/no as
  Yes/No, text as text.
- **The submitter:** the name and contact only with
  `service_feedback.view_submitter_identity`. Each view of an identity is
  audited. Without the grant the page says "Hidden — needs the submitter
  identity permission". Only a submission that carries no identity says
  "Anonymous".
- **Evidence:** names and kinds only with
  `service_feedback.view_sensitive_attachments`. Others see that files exist.
  Downloads are streamed from the private disk after the scope, permission
  and checksum checks, and each download is audited.
- **History:** every recorded change of state with actor, time and reason
  (Feedback now too: see the workflow document).

Text submitted by the public is rendered as React text, never as HTML.

## 4. Unrouted queue

A Tip-off or Complaint that no routing rule matched becomes `unrouted`
(history: "No matching routing rule"). It stays visible in the type inbox, in
All Submissions, and in the **Unrouted** queue until someone with
`service_feedback.assign` gives it a handler (user, unit or role of the
target institution). Assigning never changes the target.

## 5. Exports

| Export | Formats | Scope | Language |
|---|---|---|---|
| Submission reports (`submission-reports.export`) | CSV, Excel (queued, private, formula-safe) | `PublicFeedbackMetrics::query()`: type grants, target grants, organization scope | **requester's language**: Amharic labels, Amharic names, Ethiopian dates; or English and Gregorian (`public_feedback_report_exports.locale`) |
| Feedback CSV (`service-feedback.admin.export`) | CSV (streamed) | `ServiceFeedbackQueryService::scopedQuery()` | requester's language, as above |

Identity columns are filled only for holders of the identity grant. PDF is
not supported by this reporting architecture.

## 6. What is not here, on purpose

- **Notes:** there is no separate notes table. The reason recorded with each
  transition and assignment, and the Feedback review note, are the internal
  notes: staff-only, attributed and timed in the history. A free-standing
  "add a note without changing status" is NEEDS_POLICY_DECISION.
- **Bulk actions:** there are no bulk status changes. Export is the only bulk
  operation, and it is scoped.
- **Public tracking:** not built, so nothing internal can leak through it.
- **SLA metrics:** there is no approved SLA (SLA_CONFIGURATION_REQUIRED). The
  dashboard shows days to outcome, but no "overdue".
- **Management action:** the Situation Room Action Register exists, with a
  free-text `source_type`/`source_reference`. Creating an action from a
  submission page is not wired (NEEDS_POLICY_DECISION).
