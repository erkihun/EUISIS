# Employee transfer self-service

The employee journey is: **published opportunity → position eligibility → application → screening → selection → canonical transfer → implementation**.

Portal routes resolve the employee only from the authenticated user. They never accept an employee ID, source assignment, source organization, or workflow status from the browser.

Employees see only published opportunities whose closing date has not passed. For multi-position announcements, the employee selects one announcement-position row. Submission rechecks the open window, linked employee, active assignment, selected position ownership, deterministic eligibility, and the existing one-active-application-per-announcement policy. It records source and destination snapshots. Submitting an application does **not** change an employee assignment, occupy a position, or reserve capacity.

Documents are stored on the private `local` disk and are validated as PDF/JPEG/PNG up to 5 MB. Every required document type is enforced at submission. An employee may upload a new file or reuse an own, same-type employee document; reuse copies a private evidence snapshot into the application, so later profile changes cannot rewrite submitted evidence.

Official application numbers are generated only on the server through the active `transfer_application` CodeRule. A missing active rule blocks submission rather than accepting an unofficial identifier. Configure that rule before opening an announcement.

There is no post-close grace period: the application window is authoritative. Withdrawal is available only through submitted, under-review, or verified states, always requires a recorded reason, and is blocked once selected or in the approval chain. Draft application persistence is intentionally not enabled: implementing it would require an approved retention and one-per-announcement replacement policy; the portal keeps incomplete data in the browser only.

At selection, the application keeps its selected announcement-position. The canonical transfer and later implementation use that selected position; only implementation changes the assignment and capacity.
