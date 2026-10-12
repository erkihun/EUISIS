# Public service feedback, suggestions, and complaints

The public QR flow resolves a server-side random feedback token to the scanned employee and offers exactly three public submission types: Feedback, Suggestion, and Complaint. The browser never supplies the target employee ID.

Feedback has a 1--5 rating. Suggestions require a message. Complaints have a dedicated code-rule reference, optional incident details, private attachment storage, integrity hashes, audited downloads, organization-scoped review, and dedicated complaint permissions. The reviewed employee is excluded from review, detail, and attachment access.

Public submissions are not employee grievances. They do not create disciplinary cases, decide responsibility, assign penalties, alter employment status, or automatically modify EPMS/performance data. Suggestions and complaints have dedicated inboxes; complaints cannot be hidden or deleted by ordinary feedback actions.

No public tracking endpoint exists, because a complaint reference is not an access credential. Public endpoints are rate limited and revoked/unknown tokens produce the same generic unavailable response.

## NEEDS_DECISION

Public complaint categories, anonymous versus identified submission, contact requirements, routing authority, service-level deadlines, complainant-identity visibility, controlled HR/disciplinary referral threshold, retention, malware-scanning provider, and reporting policy are not defined by Regulation 201/2018 in the repository and need an approved service-quality policy.
