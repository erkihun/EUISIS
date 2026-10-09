# Field Work ↔ Attendance Integration

## Current state

EUISIS has **no attendance module**: no attendance tables, no device or
biometric ingestion, and no daily attendance status. So there is nothing for
field work to reconcile against yet. This module provides the read side that
a future attendance module will consume, so that approved field work never
shows up as unexplained absence.

## Contract: `FieldWorkAttendanceService`

```php
// All authorised field work overlapping [from, to], per employee.
$intervals = app(FieldWorkAttendanceService::class)->intervals($employeeIds, $from, $to);

// The covering interval at one instant, or null.
$interval = app(FieldWorkAttendanceService::class)->at($employeeId, $instant);
```

Each interval contains:

| Key | Meaning |
|---|---|
| `status` | `OFFICIAL_FIELD_WORK` |
| `field_work_request_id`, `reference_number` | The source reference, always present |
| `starts_at` | The earlier of the participant's GPS check-in and the planned start |
| `ends_at` | The participant's GPS check-out; otherwise the recorded actual return of completed work; otherwise the expected return |
| `checked_in_at`, `checked_out_at` | Actual GPS times, `null` when absent |
| `request_status` | `approved`, `in_field` or `completed` |

Only authorised statuses count (`approved`, `in_field`, `completed`). Drafts,
pending, returned, rejected and cancelled requests explain no absence.

## Rules the attendance module must keep

1. **Never fabricate device data.** Field work creates no `clock_in`,
   `clock_out` or biometric rows and never edits raw device data. Attendance
   *classifies* a gap using these intervals; it does not fill the gap with
   fake punches.
2. **Interval-aware.** A partial-day field work (for example 09:00–12:30)
   covers those hours only. The E2E test checks that 16:30 the same day is
   *not* covered. Attendance must not mark the whole day as field work, or
   the whole day as present.
3. **Keep the reference.** Any attendance status derived from field work must
   store `field_work_request_id`.
4. **Conflicts are reported, not overwritten.** Overlapping leave, training or
   travel must surface as a conflict for HR. Field work already refuses
   submission and approval when the leave provider reports approved full-day
   leave, or when another open field work overlaps.

## Leave

Conflict detection already calls the existing `EmployeeLeaveProvider`
contract, whose current binding is `NullEmployeeLeaveProvider` (it reports no
leave). When a leave module binds a real provider, field work starts
honouring leave with no code change.

## NEEDS_DECISION

- Whether the attendance status is `OFFICIAL_FIELD_WORK` or the more general
  `OFFICIAL_DUTY`. The constant is
  `FieldWorkAttendanceService::ATTENDANCE_STATUS`.
- How an approved request with no GPS check-in is treated. Today the interval
  still covers the planned window, and the request is flagged
  `check_in_missing` in Field Work monitoring.
- Training and official travel/mission providers for conflict detection.
- The "Attendance conflicts" dashboard figure. It waits for attendance data.
