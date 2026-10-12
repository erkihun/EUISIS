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

Submitter name/contact follow the public feedback policy's identity mode
(anonymous: nothing collected; identified: name and a valid phone or e-mail
required; optional). The mode in force is recorded on the row and the contact
is encrypted at rest; only `service_feedback.view_submitter_identity` shows
it. IP address and user agent are retained for abuse investigation but
excluded from Inertia payloads. Submission routes use CSRF, a one-time form
token, a duplicate guard and the token/IP rate limiter whose thresholds come
from the policy (`rate_limit_per_source`, `rate_limit_per_ip`) and which runs
on the configured cache limiter store. The server validates any selected
position service against the target employee's current position, regardless
of what the browser posts. There is no employee search or target switch.

Complaint attachments are limited to three files of at most 5 MB each, with
PDF/JPEG/PNG extension **and** detected MIME validation, and a client file
name without path parts, control characters or an executable/script
extension anywhere in it. They are stored under random names on the private
`local` disk. Listing and downloading them takes
`service_feedback.view_sensitive_attachments` on top of complaint view and
organization scope; downloads check the file SHA-256, emit an audit event and
use no-store/nosniff headers. No antivirus is installed; records are marked
`not_scanned`, and files are never served through public URLs or inline
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

Since 2026-10-12 the typed submissions share one workflow engine
(`docs/public-feedback.md` §4): a suggestion goes pending → assigned or
unrouted → under review → accepted or not adopted → closed; a complaint goes
pending → assigned or unrouted → under review → resolved → closed. New
suggestions and complaints are routed to a handler by routing rules or
marked unrouted and brought to the routing administrators; every transition
and hand-over is kept in an immutable history. A complaint cannot be
hard-deleted or hidden through the ordinary feedback actions. The public
submission creates no disciplinary action; an authorised review may request
a controlled discipline referral, which is a request record only. Categories
are managed per city and per institution. There is still no automatic
escalation/SLA or automatic retention job under the approved policy.
Complaint references and attachment metadata remain in the audit trail;
review notes, messages and contact details are not copied into audit events.

## Performance boundary

Public form submission no longer calls `ServiceFeedbackPerformanceSync` and
therefore does not immediately create or alter an EPMS actual. The existing
configured `service_feedback.average_rating` system KPI source is a separate
legacy integration; whether to disable that source entirely is an explicit
rollout decision. No historical KPI actual is deleted automatically.

## Deployment and rollback

Run the additive migrations (the two of the typed baseline and the seven of
2026-10-12 listed in `docs/public-feedback.md` §2), build frontend assets,
and assign the complaint and public-feedback permissions to the selected
roles. Confirm the private `local` disk is backed
up and accessible only through the application. Exercise a real scan and an
authorized attachment download before opening the public feature.

The schema rollback refuses to drop columns while non-feedback submissions,
nullable ratings or attachment records exist. Export/migrate those records
first; do not force a destructive rollback. Permission grants and the complaint
code rule are preserved during rollback for recovery and audit history.
