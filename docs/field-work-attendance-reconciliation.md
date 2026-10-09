# Field Work attendance reconciliation boundary

## Architecture found

EUISIS currently has no Attendance module, attendance daily-summary table, biometric-event model, shift/work-schedule engine, leave-record module, training module, or official travel/mission module. The only leave seam is `EmployeeLeaveProvider`, which is currently bound to `NullEmployeeLeaveProvider`. Daily Activity is explicitly a work register, not attendance.

Accordingly, this release does not create an attendance engine, an `ABSENT` status, biometric clock records, or synthetic device evidence. Approved Field Work cannot be automatically converted into unexplained absence because no attendance classifier exists.

`AttendanceReconciliationService` is a read-only adapter boundary. It returns date-clipped participant session intervals (so partial-day Field Work is never expanded into a whole-day result) and may return `official_field_work` context with `status_source = field_work`, `reference_type = field_work_request`, and the immutable request id. It always returns `needs_decision` and writes no attendance record. This preserves the required provenance without pretending that an authoritative attendance result exists.

## Future authoritative integration

When attendance is introduced, it must consume participant sessions interval-by-interval, preserve raw biometric imports unchanged, and store Field Work as interpreted context rather than a biometric event. Partial-day intervals must remain intervals; full-day classification, leave/training/travel precedence, tolerance, retroactive authorization, and correction workflow require formal policy before implementation.
