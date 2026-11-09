# Grievance SLA and escalation

`GrievanceSlaService` resolves an effective `GrievanceSlaProfile` by purpose, handler, category, organization and priority. Exact handler beats handler type, which beats an unrestricted profile. Each new stage copies the profile's days, day type, start point and automatic-escalation flag. Later profile edits do not rewrite those stage fields.

## Clocks and calendar

Start points are assignment, receipt or acceptance. A clock starts once when its configured event occurs. Working days use the shared `WorkingDayCalculator`, configured workweek and public-holiday data; calendar-day profiles use calendar arithmetic. Public holidays support Gregorian and Ethiopian recurrence. The stage preserves original and current due dates. A missing SLA profile means no automatic clock or escalation, not a guessed legal deadline.

The default committee profile carries forward **three working days** from the first module. It is a configurable baseline and has not been certified as statutory policy. The default workweek is Monday-Friday. Warning thresholds and due-soon settings are configurable.

## Pause and resume

A pause records its reason, requestor, approval and timestamps. Permission and handling authority determine whether a request activates immediately or waits for approval. Active pauses prevent timeout escalation. Resume records elapsed working/calendar days and extends the current deadline while keeping the original deadline for audit. Review the configured workweek and recurring holidays before relying on deadlines.

## Scheduler

`routes/console.php` schedules `grievances:process-sla` every fifteen minutes with `withoutOverlapping()` and `onOneServer()`. Production must actually run Laravel's scheduler and use a cache backend shared by every scheduler node for locks. Page views do not drive escalation.

`GrievanceEscalationService` sends warnings and processes overdue open stages. It rechecks current stage, completion, pause, pending executive approval, deadline and prior successor under database locks. A second invocation must not create a second successor. No valid next route records a blocked event. Individual errors are logged so other cases continue and the failed case can retry.

Pending executive approval is excluded from handler timeout escalation. Appeal windows are separately configured; automatic closure after appeal expiry is off by default. Monitor sweep results, blocked events, errors and scheduler health. A command's successful exit is not proof that every case moved.

## NEEDS_DECISION

Confirm deadlines by handler/category/purpose, the event starting each clock, pause grounds and approvers, holiday ownership, warning recipients, executive-approval deadlines and escalation, and the legal event starting an appeal period. PostgreSQL concurrency and multiple scheduler-node behavior require deployment validation beyond sequential SQLite tests.
