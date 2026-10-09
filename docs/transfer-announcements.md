# Transfer Vacancy Announcements

Announcements follow this controlled lifecycle:

`Draft → Readiness validation → Staff preview → Publish → Application window → Close → Screening / selection → Complete`

Announcements are drafts until publication. The detail page is the control centre for the record: it shows the configured positions, advertised slots beside live available capacity, the derived application-window state (`Scheduled`, `Open`, or `Closed`), readiness results, and permission-gated aggregate application counts. It does not load applicant records; the application list remains a separately authorized, paginated route.

Publication is an explicit server-side action. `TransferAnnouncementReadinessService` verifies destination organization, dates, structured eligibility rules, positions, and live approved capacity. The publish action repeats that assessment after locking the announcement and capacity rows, records only the authenticated publisher and timestamp, and writes the audit event. Browser input cannot set the status or publisher.

Drafts alone are normally editable. Once published, positions, advertised slots, eligibility rules, and dates are immutable through the normal update path, preserving what applicants applied against. The staff preview route is view-authorized and never exposes an apply action.

Only published announcements whose closing date has not passed are returned to public and employee listings. Application submission independently checks `isAcceptingApplications()`, so an early close or elapsed date cannot be bypassed by a direct POST. Close and cancel are row-locked status transitions. Neither removes applications, and cancellation never deletes or reverses an already-created canonical `EmployeeTransfer`.

The position master remains authoritative; announcement position rows only carry the offered vacancy count and presentation attributes. Assignment changes never occur in announcement creation, publication, closure, or cancellation.

**NEEDS_DECISION:** official announcement numbering requires a dedicated CodeRule entity/rule; amendment/versioning, mandatory cancellation reason storage, early-close authority, cancellation effects on active applications, and position-level application attribution require approved policy/schema. Current applications are announcement-level, so position-level application/selection counts cannot be truthfully reported until an application-position relation is introduced.
