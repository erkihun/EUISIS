# ADR 0018: Service Delivery Monitoring & Support as a module

- Status: accepted (2026-10-30)
- Context: the quarterly service-delivery report of every institution (Forms A and B, FY 2019 E.C.)
  is collected on paper and messaging apps and re-typed for consolidation. The specification for
  the digital process fixed decisions D1–D27.

## Decision

Build it as `Modules/ServiceMonitoring` under ADR 0016/0017: its own 29 tables
(`service_monitoring_*`), models owned by the module (`Modules/ownership.php`), and one public
contract, `ServiceMonitoringIndicators`, for the Situation Room. It reads other domains only:
Discipline decisions, employees, assignments, ID cards and the service catalog through their
models (they are still in `app/`), cafeteria locations through Cafeteria's public
`CafeteriaDirectory`. It writes no other module's table.

Decisions taken during the build where the specification left room (recorded with their reasons in
[service-monitoring.md](../../service-monitoring.md#decisions), D28–D41): the module location; a
second "one accepted revision" unique index; resubmission after a return until lock; lock only
after every sub-city certified; a table for "recorded as not submitted"; a table for queued exports;
the definition of a final disciplinary decision; the cafeteria reference through the public
contract; sub services inside the module because the catalog has no hierarchy; reminder timing;
form text not given verbatim marked for checking; city-wide reach; named data withheld from the
city-wide admins.

## Consequences

- The official 3-, 6-, 9-month and annual reports are always derived from quarterly reports, so
  they reconcile by construction; locked periods read immutable snapshots.
- Form wording lives once (`FormDefinition` + `lang/{am,en}/service-monitoring.php`) and drives
  validation, the screens and the exports.
- Before go-live the Amharic headings and notes marked NEEDS_FORM_TEXT_CHECK must be compared with
  the printed forms by the forms' owner; no sign-off is recorded here.
