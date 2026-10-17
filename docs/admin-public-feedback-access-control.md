# Admin: Public Feedback access control

**Permission decides WHAT a user may do. Organization scope decides WHERE.**
Both are checked on the server for every list, count, detail page, action,
file and export. The sidebar and the page only hide what the server would
refuse anyway.

## 1. Grants (WHAT)

| Grant | Allows |
|---|---|
| `service_feedback.view` | Feedback and Tip-offs: lists, detail pages, dashboard, reports |
| `service_feedback.complaints.view` | Complaints (a narrower grant, for selected service-quality roles) |
| `service_feedback.view_organization_target` / `service_feedback.view_employee_target` | submissions about an institution / about an employee. Each is a separate grant |
| `service_feedback.view_submitter_identity` | the submitter's name, phone, email, organization and address (each view audited) |
| `service_feedback.view_sensitive_attachments` | evidence files (each download audited) |
| `service_feedback.review` / `service_feedback.complaints.review` | Feedback: reviewed, resolved. Tip-off/Complaint: under review |
| `service_feedback.resolve` / `service_feedback.close` | record an outcome / close |
| `service_feedback.assign` / `service_feedback.reassign` | first handler / change of handler |
| `service_feedback.hide`, `service_feedback.delete` | moderate Feedback and Tip-offs (never Complaints) |
| `service_feedback.export` | CSV/Excel exports, within the user's scope |
| `service_feedback.refer_discipline` | request or decide a controlled referral of a reviewed Complaint |

`PublicFeedbackVisibility::visibleTypes()` and `applyTypeScope()` turn the two
view grants into the set of types a user may read. They are applied by
`PublicFeedbackMetrics::query()`, so the dashboard overview, All
Submissions, the cards, the submission reports and their export never carry
a Complaint to someone without the Complaint grant. **Fixed in this phase:**
before, that query applied only scope and target grants, so a holder of
`service_feedback.view` alone could export complaint narratives.

## 2. Scope (WHERE)

`OrganizationScopeService` (unchanged):

- `self`: the user's own organization;
- `subtree`: it and every descendant in the **published** hierarchy
  (`organization_closure_paths`);
- city-wide or unrestricted role: everything.

Rows are scoped on `employee_service_feedback.organization_id`, the
institution the submission is about (for an employee, the employee's
organization at submission). A row with no organization is visible only to
unrestricted roles.

| Viewer (subtree) | Feedback, Tip-off, Complaint about Woreda A1 |
|---|---|
| Woreda A1 | visible |
| Subcity A (parent) | visible |
| Bureau (grand-parent) | visible |
| Woreda A2 (sibling) | not listed; detail 403 |
| Subcity B (other branch) | not listed; detail 403 |

Tested in `tests/Feature/PublicFeedback/AdminPublicFeedbackManagementTest.php`
for organization and employee targets.

## 3. Enforcement points

| Surface | Check |
|---|---|
| All Submissions, Unrouted, cards | `PublicFeedbackMetrics::query()`: types, targets, scope, never the user's own submissions. Filters only narrow it; a forged organization, unit, category or handler id returns nothing (tested) |
| Feedback inbox, dashboard, reports, CSV | `ServiceFeedbackQueryService::scopedQuery()` (types: Feedback only) |
| Tip-off/Complaint inbox | `ServiceSubmissionController::scoped()` |
| Detail, transition, assign, subjects, referrals | `assertAccess()` and the `EmployeeServiceFeedbackPolicy` (`view`, `review`, `transition`, `assign`): grant, target grant, scope, and never the employee the submission is about |
| Evidence download | the above, plus the evidence grant, the attachment belonging to that submission (else 404), and the file checksum |
| Submission reports export | a 403 for an organization outside scope; rows from `PublicFeedbackMetrics::query()`; identity columns only with the grant; the download only for the requester or a scope holder |
| Filter options | organizations, categories, types and target types limited to what the user may use |

IDOR: opening `/feedback-admin/{id}` or `/service-submissions/{type}/{id}` for
a row out of scope is a 403. So are the transition, assignment, review,
hide and download endpoints (tested).

## 4. Target and handler

The **target** (`target_type`, `organization_target_id`, `employee_id`, the
target snapshots) is set once by the public submission, from the scanned
QR. No admin endpoint accepts a target field, and no request field can
change one: assignment writes only `public_feedback_assignments`, and
transitions write only status and timestamps. Routing and handlers change
internally; the target never does (tested after assignment and a full
workflow).

## 5. Privacy in payloads

- The lists carry no submitter identity and no narrative, even for an
  identity holder (tested).
- The detail pages send identity fields as `null` without the grant (tested).
- Situation Room aggregates carry counts only
  ([organization-feedback-analytics.md](organization-feedback-analytics.md)).
- `ip_address` and `user_agent` never leave the server.
