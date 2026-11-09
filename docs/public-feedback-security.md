# Public feedback security

Threat model and controls for the unified public feedback flow (employee QR
and institution QR; rating, suggestion and complaint), with the security
matrix the QA brief asks for (§89). It complements
[employee-feedback-security.md](employee-feedback-security.md) (the original
employee rating flow) and [public-feedback.md](public-feedback.md) (data model
and workflow). Every statement below is backed by a test named in the last
section; nothing here is a design intention without a proof.

## 1. Assets and trust boundaries

| Asset | Where it lives | Who may see it |
| --- | --- | --- |
| Employee QR secret (64-hex `employee_feedback_tokens.token`) | printed on the ID card, in the URL a citizen opens | anyone holding the card; the server treats it as capability to *open the form about that employee*, nothing else |
| Institution QR secret (`organization_feedback_qr_codes.public_token`) | the institution's poster | anyone at the counter; same capability for the institution |
| Submission narrative, rating, contact | `employee_service_feedback` | handlers in scope; the contact only with `view_submitter_identity` |
| Complaint attachments | private `local` disk, `service-feedback/<submission id>/<uuid>.<ext>` | handlers in scope with `view_sensitive_attachments`, via an audited download |
| Submission IP / user agent | `employee_service_feedback` (`$hidden`) | never serialised to a browser; abuse investigation only |
| Audit trail | `audit_logs` | auditors; rows never carry narrative, contact or token |

Trust boundaries: the public pages (unauthenticated, throttled, CSRF-protected,
one-time form token) · the management area (session, permission,
`OrganizationScopeService`, target-type grant) · the queue (notifications and
exports run as the requester, after commit).

## 2. Controls

**Target determined server-side.** The QR token resolves to exactly one
target; the resolver returns `null` for a revoked, suspended or unknown token
(404 page, no scan counted, no hint which case applied). Every forged field
in the POST (`employee_id`, `employee_target_id`, `organization_target_id`,
`organization_id`, `employee_assignment_id`, `target_type`, `source_type`,
`type`, `status`, `resolved_at`, `closed_at`, `assigned_at`, `reviewed_by`,
`reviewed_at`, `review_note`, `reference_number`, `submitter_identity_mode`,
`ip_address`) is ignored on both flows. `/grievance` and `/appeal` are not
types (404). PostgreSQL CHECK constraints (`esf_target_integrity_chk`,
`esf_source_target_chk`) make an employee row with an institution target, or
a mismatched source, impossible to store at all.

**Original target never changes.** No code path writes `employee_id`,
`organization_target_id` or `target_type` after insert. A handler, a related
employee subject or a discipline referral are separate rows
(`public_feedback_assignments`, `public_feedback_subject_employees`,
`public_feedback_referrals`). Status history is an immutable model.

**Replay, double-submit and abuse.** The form carries a one-time token (cache
backed, TTL bounded) that cannot be replayed against another target; a
double POST stores once (content fingerprint). The `service-feedback-submit`
limiter counts per QR code and per client address over ten minutes with
thresholds from the policy settings, on the shared cache store, so a crowd
behind one balancer is limited per address and a spammer per code. Uploads:
PDF/JPEG/PNG only, MIME and extension checked, 5 MB each, bounded count,
traversal and double-extension names refused, stored under a server-chosen
UUID name, SHA-256 recorded, `scan_status` reserved for a scanner
(NEEDS_DECISION: no scanner is contracted). No CAPTCHA is deployed
(NEEDS_DECISION, see [public-feedback.md](public-feedback.md) §9).

**Identity and privacy.** `identity_mode` is a city-wide policy (anonymous /
identified / optional); the inbox, detail, reports and CSV mask name and
contact without `view_submitter_identity`. The acknowledgement e-mail (off
by default) carries type, reference and time, never the text. Internal
notifications carry a reference and a link, never the narrative. The log
never receives the narrative, the contact or either QR secret (proved for all
six combinations, for a notification failure and for a storage failure).

**Conflict of interest.** The employee a submission is about cannot open,
list, assign, transition, download or refer it whatever role they hold
(`abort_if` on the user's `employee_id`); routing never assigns to the target
(`handler_is_target`); a reviewer who is the target or a named subject cannot
request or decide a referral.

**Scope.** Every management query applies the permission, then
`OrganizationScopeService` (the submission's context institution), then the
target-type grant. A handler outside the institution gets 403 on every
detail, transition, assignment, download, subject and referral route, and a
total of 0 in lists and reports. Sequential or guessed ids are never
accepted (UUIDs, `whereUuid`).

**Domain boundaries.** A public complaint is not a grievance, an appeal or a
discipline case: no row is written in `grievances`,
`administrative_tribunal_cases` or `disciplinary_cases` by any public or
workflow action; a referral creates a `public_feedback_referrals` row only,
and a decision does not open a case (NEEDS_DECISION on who opens it). No
public submission touches EPMS: the only link is the opt-in KPI source
`service_feedback.average_rating`, which reads ratings of type `feedback`
only (a rating smuggled onto a complaint is not counted) and writes nothing
by itself; the employee record is byte-for-byte unchanged by resolving and
referring a complaint.

**Failure behaviour.** A storage or database error during a submission rolls
everything back (row, history, audit, any file already written), returns the
citizen to the form with a translated message and no receipt, and logs a
query exception by class and SQLSTATE only. A notification provider that
throws never fails the submission. Without a queue worker an export stays
visibly `queued` and cannot be downloaded. With the cache store down the
throttle middleware throws, so the public form fails **closed** (HTTP 500),
not open — framework behaviour, not measured on this rig.

**Observability.** Every public response carries `X-Request-ID`
(`RequestCorrelationId`, prepended to the middleware stack). Routing logs a
warning naming only the rule id when a rule cannot be applied.

## 3. Security matrix (§89)

Roles come from `app/Support/Rbac/DefaultRoleMatrix.php`. The default matrix
ships **no handler role**: `Super Admin` and `System Admin` hold every
permission, `Organizational Admin` holds view / review / hide / export /
settings.manage and both target grants, and every other feedback permission
(complaints, assignment, resolution, routing, categories, institution QR,
identity, attachments, referral) is granted to no role until an
administrator composes one. "Feedback Handler" below is therefore the role
the tests compose ("Service Quality Team") and the one the deployment is
expected to create; "Supervisor" is not a role in this product.

| Role | Permissions (service_feedback.*) | Scope | Target types | Allowed | Denied |
| --- | --- | --- | --- | --- | --- |
| Super Admin | all (`Gate::before`) | unrestricted | both | everything, including city-wide routing rules, categories and policy settings | act on a submission about *themselves* (target guard applies to every role) |
| System Admin | all | unrestricted | both | as Super Admin | same |
| City / Public Service Bureau Admin | as granted by the administrator (default matrix: none of the feedback set beyond what the role was given) | unrestricted or city-wide | both | city-wide rules/categories only with unrestricted scope | institution-scoped actions outside scope |
| Organizational Admin | view, review, hide, export, settings.manage, view_employee_target, view_organization_target | own institution(s) | both | see and review ratings, hide/unhide, export in scope, manage the institution's employee QR codes | complaints and suggestions (no `complaints.*`), assignment, routing, categories, identity, attachments, referral, the city-wide policy page (needs unrestricted scope), any other institution |
| Feedback Handler (composed, e.g. "Service Quality Team") | complaints.view, complaints.review, assign/reassign, resolve, close, view_employee_target, view_organization_target (+ optionally view_submitter_identity, view_sensitive_attachments, refer_discipline) | own institution | both, or one of them if a grant is withheld | open, acknowledge, review, assign, resolve and close suggestions and complaints in scope; see identity/attachments only with the specific grant | another institution's submissions (403 / total 0), a submission about themselves, retargeting (no such action exists), discipline case creation |
| Target Employee (any role) | whatever their role holds | n/a | n/a | submit nothing about themselves differently from any citizen | open, list, assign, transition, download, add subjects to or refer a submission about themselves — 403 everywhere, hidden from their inbox |
| Ordinary Employee | none of the feedback set | own record | none | nothing in the management area (sidebar group hidden, routes 403) | everything above |
| Citizen (no account) | none | n/a | the one target the QR resolves to | open the landing and the three forms, submit within throttle, receive a reference | choose a target, choose a type that is disabled, replay a form token, exceed the throttle, upload executables, reach any management page |

## 4. Proofs

| Control | Test |
| --- | --- |
| Target server-side, forged fields ignored, replay, double POST, grievance/appeal 404 | `tests/Feature/PublicFeedback/PublicFeedbackTamperingTest.php` |
| Six combinations end to end, scope 403 / total 0 | `PublicFeedbackMatrixTest.php` |
| Revoked / disabled / unknown tokens, DB constraints | `TargetResolverTest.php`, `TargetIntegrityTest.php` |
| QR stability through every organisational change; rotation | `PublicFeedbackQrStabilityTest.php`, `OrganizationFeedbackQrTest.php` |
| Transfer, rename, deletion resistance, query budget | `PublicFeedbackContextTest.php` |
| 403 sweep, sequential ids, XSS, audit hygiene, no grievance rows | `PublicFeedbackHardeningTest.php` |
| Throttle per code and address, uploads | `PublicFeedbackAbuseTest.php` |
| Identity masking, attachment grant, target-type grants | `PublicFeedbackVisibilityTest.php` |
| Workflow transitions, mass-assignment of status | `PublicFeedbackWorkflowTest.php` |
| Routing precedence, unrouted, unusable rule fall-through, scope of handlers | `PublicFeedbackRoutingTest.php` |
| Subjects, referral, conflict of interest | `PublicFeedbackReferralTest.php` |
| KPI source reads ratings only; employee unchanged; dashboard counts ratings only | `PublicFeedbackBoundaryTest.php` |
| Storage rollback, notification failure, queue absent, log hygiene, correlation id | `PublicFeedbackResilienceTest.php` |
| Data quality report / repair; production gate | `PublicFeedbackOperationsTest.php` |
