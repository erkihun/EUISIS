# Employee service feedback QR

## Scan and submission flow

The **Feedback and Suggestion QR** on the card back carries only the existing
`/service-feedback/{token}` URL. It is separate from the ID-card verification QR
and its OTP flow. Neither QR is rotated by this feature. The 64-character random
feedback token resolves the target employee on the server; the browser never
selects or submits `employee_id`.

Scanning opens a mobile-first landing page that shows the employee's **photo
and full name** and then exactly three options: Feedback (አስተያየት), Suggestion
(ጥቆማ), and Complaint (ቅሬታ), under the prompt "ለዚህ ሠራተኛ ምን ማቅረብ ይፈልጋሉ? /
What would you like to submit for this employee?". Each typed form re-resolves
the same token on submission.

The token resolves only while the employee is active **and holds an ACTIVE ID
card** (`Employee::activeIdCard`: status active, not revoked, not expired, QR
reference not revoked) — no employee service while the card is not active.
Unknown, revoked and suspended tokens, inactive employees and employees whose
card is inactive, suspended, lost, revoked, expired or missing all show one
generic unavailable state with no name or photo. The check runs on every page
view, photo request and submission, so a card revoked while the form is open
blocks the send.

## Identity confirmation: photo and name only

The employee page receives `PublicEmployeeFeedbackIdentity` —
`{target_type, display_name, photo_url}` — and nothing else: no employee number,
national ID, phone, e-mail, date of birth, address, office, unit, position, HR
history or internal id. `display_name` follows the ID card's own name rule
(`Employee::fullNameAm()` / `fullNameEn()`) in the visitor's language. The
organization QR page is unchanged.

The photo stays on the private disk. `photo_url` is a relative signed link,
`/service-feedback/{token}/photo?expires=…&v=…&signature=…`, valid for 10–15
minutes (the expiry is rounded to a five-minute window so every screen of a
visit reuses one URL). The token in the path decides whose photo it is — there
is no employee id to change — and the route re-checks token, employee and card
on each request, so revoking the card ends the photo at once. It serves a
384 px derived copy (`EmployeePhotoStorage::thumbnail`, made once on first
request under `employee-photo-thumbnails/` on the private disk, removed with the
photo; the original is never altered), only as JPEG/PNG/WebP after MIME
sniffing, under the fixed name `photo.{ext}`, with `Cache-Control: private,
max-age=300`. A missing photo, an inactive card and an invalid link all answer
404 and the page shows a neutral avatar; the name and choices remain.

All `/service-feedback/*` and `/feedback/*` responses carry `X-Robots-Tag:
noindex, nofollow, noarchive`, `Referrer-Policy: no-referrer` and (pages)
`Cache-Control: no-store, private` (`PrivateQrPageHeaders`, alias
`qr.private`); the layout repeats the robots and referrer meta tags. The site
has no sitemap and the CSP admits no third-party script or image. These
controls deter indexing and bulk scraping; they cannot stop someone who holds
a valid code from viewing or photographing what the page shows.

The OTP-gated ID Checker (`/id-checker/{card}`) is unchanged and shares no
response with this page.

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
from the policy (`rate_limit_per_source`, `rate_limit_per_ip`); the photo route
has its own `service-feedback-photo` limiter (30/min per token and address,
120/min per address). All limiters run
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
