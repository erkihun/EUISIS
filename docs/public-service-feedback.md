# Public service feedback, suggestions, and complaints

The public QR flow resolves a server-side random token to exactly one target
— the scanned employee (card QR) or the scanned institution (institution
feedback QR) — and offers exactly three public submission types: Feedback,
Suggestion, and Complaint. The browser never supplies the target. The full
architecture, tables, routes, permissions, policy settings and the
NEEDS_DECISION registry are in `docs/public-feedback.md`; the UAT record is
`docs/public-feedback-uat.md`; the readiness gate is
`docs/public-feedback-readiness.md`.

Feedback has a 1–5 rating. Suggestions require a message. Complaints have a
dedicated code-rule reference, optional incident details, private attachment
storage, integrity hashes, audited downloads, organization-scoped review, and
dedicated complaint permissions. Submissions carry a category where the
institution defines one, the identity mode in force (anonymous, identified or
optional), encrypted contact details, and are routed to a handler by routing
rules or marked unrouted for an administrator. Each type has its own workflow;
every transition and hand-over is kept in an immutable history. The target
employee is excluded from review, detail, assignment, referral and attachment
access.

Public submissions are not employee grievances. They do not create
disciplinary cases, decide responsibility, assign penalties, alter employment
status, or automatically modify EPMS/performance data. An authorised review
may name investigation subjects and request a controlled referral to the
discipline domain; the referral is a request record, never a case.

No public tracking endpoint exists, because a complaint reference is not an
access credential. Public endpoints are rate limited per QR code and per
address with policy-set thresholds, protected by a one-time form token and a
duplicate guard, and revoked, disabled or unknown tokens produce the same
generic unavailable response.

## NEEDS_DECISION

Service-level deadlines, retention and withdrawal, per-institution policy
overrides, the suggestion outcome vocabulary, public tracking, CAPTCHA, SMS
acknowledgement, target-employee self-view, a malware-scanning provider,
throttle thresholds, which roles receive which grants, discipline case
creation from an accepted referral, follow-up action items and reference
numbers for feedback and suggestions are not defined by Regulation 201/2018
in the repository and need an approved service-quality policy. Each has a
documented safe default in `docs/public-feedback.md` §9.
