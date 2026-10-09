# Field Work Location Security and Privacy

Precise location is sensitive HR data. Capture and visibility are separate permissions: capture-own, view-own, view-team, view-org, precise-view, and export. GPS endpoints contain no employee identifier, are authenticated/CSRF protected, rate-limited, authorize the participant server-side, and create immutable records with audit events.

Ordinary Field Work access must not imply access to exact coordinates. General Field Work reports should use verification status rather than coordinates. There is no public endpoint, ordinary export, or location edit operation.

Idempotency replay is accepted only when the key belongs to the same request,
participant, employee, and event type. A blocked observation remains immutable
evidence but does not occupy the single authoritative check-in/check-out slot;
the participant may explicitly retry with a new observation and idempotency key.

GPS events do not create biometric clock-ins, attendance records, performance scores, or task completion. A future attendance adapter may consume a successful event as an `OFFICIAL_FIELD_WORK` provenance signal only after attendance policy is approved.

## Operational decisions still required

- An authorized System Settings administrator must configure mandatory capture, accuracy and outside-geofence actions; defaults deliberately do not enforce an undeclared policy.
- Legal and records owners must approve retention and privileged coordinate-export rules. Retention is recorded in policy settings but is not yet an automated deletion job.
- Map provider/destination coordinate governance, team-leader evidence policy, and offline synchronization remain out of scope for this point-in-time browser capture.
