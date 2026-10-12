# Employee service feedback QR

## Scan and submission flow

The **Feedback and Suggestion QR** on the card back carries only the existing
`/service-feedback/{token}` URL. It is separate from the ID-card verification QR
and its OTP flow. Neither QR is rotated by this feature. The 64-character random
feedback token resolves the target employee on the server; the browser never
selects or submits `employee_id`.

Scanning opens a mobile-first landing page with exactly three options:
Feedback (አስተያየት), Suggestion (ጥቆማ), and Complaint (ቅሬታ). Each typed form
re-resolves the same active token on submission. Unknown, revoked and suspended
tokens show one generic unavailable state. Public pages show only the office,
unit and role label already allowed by the feedback QR policy. They do not show
the employee's name, photo, number, phone or other HR data.

Feedback keeps the existing required 1–5 rating and optional comment. Suggestion
requires text and has no rating. Complaint requires a description, may include
incident date/location and up to three attachments, and receives a stable
`PSC-{YEAR}-{SEQUENCE}` code-rule reference. No public tracking endpoint is
provided: a reference alone is not an access credential. All three types use
the current employee assignment ID plus organization/unit/position snapshots,
so transfers do not rewrite submission history. Existing feedback rows are
backfilled by the database default `type=feedback`.

## Privacy and abuse controls

Submitter name/contact are optional. IP address and user agent are retained for
abuse investigation but excluded from Inertia payloads. Submission routes use
CSRF and the existing token/IP rate limiter. The server validates any selected
position service against the target employee's current position, regardless of
what the browser posts. There is no employee search or target switch.

Complaint attachments are limited to three files of at most 5 MB each, with
PDF/JPEG/PNG extension **and** detected MIME validation. They are stored under
random names on the private `local` disk. Downloads require current complaint
view permission and organization scope, check the file SHA-256, emit an audit
event and use no-store/nosniff headers. No antivirus is installed; records are
marked `not_scanned`, and files are never served through public URLs or inline
preview. Operators should provision malware scanning before allowing staff to
open untrusted documents outside the application.

## Routing and review

Feedback stays in the existing rated-feedback dashboard and reports. Suggestions
have their own inbox using `service_feedback.view` / `service_feedback.review`.
Complaints have a separate inbox and dedicated
`service_feedback.complaints.view` / `.review` permissions. The migration creates
these permissions but grants them to **no** role: an administrator must assign
them to selected service-quality roles before launch. All inboxes enforce the
user's organization scope. The target employee is excluded from lists, details,
review and attachment downloads even if they hold an administrative role.

Complaint and suggestion review uses Pending → Reviewed → Resolved. A complaint
cannot be hard-deleted or hidden through the ordinary feedback actions. The
public submission creates no disciplinary action. There are no complaint
categories, automatic escalation/SLA or automatic retention job under the
approved policy. Complaint references and attachment metadata remain in the
audit trail; review notes and messages are not copied into audit events.

## Performance boundary

Public form submission no longer calls `ServiceFeedbackPerformanceSync` and
therefore does not immediately create or alter an EPMS actual. The existing
configured `service_feedback.average_rating` system KPI source is a separate
legacy integration; whether to disable that source entirely is an explicit
rollout decision. No historical KPI actual is deleted automatically.

## Deployment and rollback

Run the two additive migrations, build frontend assets, and assign complaint
permissions to the selected roles. Confirm the private `local` disk is backed
up and accessible only through the application. Exercise a real scan and an
authorized attachment download before opening the public feature.

The schema rollback refuses to drop columns while non-feedback submissions,
nullable ratings or attachment records exist. Export/migrate those records
first; do not force a destructive rollback. Permission grants and the complaint
code rule are preserved during rollback for recovery and audit history.
