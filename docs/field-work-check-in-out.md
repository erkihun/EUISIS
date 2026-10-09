# Field Work check-in and check-out

An approved Field Work request authorizes offsite work. It does not create a biometric attendance event and it does not establish task completion.

Each participating employee records their own explicit browser GPS check-in and check-out. The server resolves the employee and participant from the authenticated account, stores a separate immutable location event, then commits a `field_work_sessions` record in the same transaction. A session has no coordinates: it holds only the approved interval and the individual checked-in/checked-out timestamps.

The unique request/participant session and immutable event uniqueness prevent double-click and retry duplication. A blocked GPS policy observation remains recorded for audit but is marked `gps_blocked`; it does not satisfy check-in or check-out. Check-out never overwrites check-in evidence. Request-level completion remains a separate workflow and GPS alone never proves completed work.

## Current time-window policy boundary

The repository has no approved check-in tolerance, late-arrival, or missing-check-out policy. Sessions retain authoritative server receipt times, but this release does not convert early/late captures into misconduct or a fabricated return time. Configure and approve those rules before enforcing them.
