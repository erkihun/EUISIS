# Daily Activity workflow

All state changes go through `DailyActivityService`. Controllers never change a status directly. The status enum (`DailyActivityStatus`) defines the allowed transitions.

```
DRAFT ──submit──► SUBMITTED ──approve──► APPROVED ──reopen (reason)──┐
  ▲                   │                                               │
  │                   └──return (comment)──► RETURNED_FOR_CORRECTION ◄┘
  │                                              │
  └─ employee edits only here and in DRAFT ──────┴──resubmit──► RESUBMITTED ──approve / return…
```

`UNDER_REVIEW` exists as an awaiting-review state alongside `SUBMITTED` and `RESUBMITTED`.

| Action | Who | Rules |
|---|---|---|
| Save draft | The employee, for their own date | Date open for entry (below); items normalized; services and EPMS links validated |
| Submit | The employee | At least one item; title and description on every item; output when required; late reason when required; first-submission lateness recorded once |
| Return for correction | Assigned reviewer | Comment of at least 5 characters required; optional per-item notes; employee notified |
| Resubmit | The employee | Same checks as submit; the earlier submission's snapshot stays in history |
| Approve | Assigned reviewer | Optional comment; employee notified when configured |
| Reopen | Reviewer or scoped oversight with `reopen` | Approved logs only; reason required; returns the log to the employee |

## Entry window

A date is open for entry when it is not in the future, the employee is employed and assigned that day, it is a working day that is not a holiday or approved leave, and it is within the backdating window. A returned log can always be corrected, however old.

## Integrity guarantees

- **One header per employee and date.** A unique index plus insert-or-ignore and a locked re-read: concurrent first saves converge on one row with no error.
- **Row locks.** Every transition locks the header (`SELECT … FOR UPDATE`) and re-checks the status inside the lock.
- **Idempotent submit.** Submitting an already-submitted day returns it unchanged.
- **No silent edits.** Submitted, awaiting-review and approved logs refuse item and evidence changes; only `RETURNED_FOR_CORRECTION` and `DRAFT` are editable.
- **Stale review refused.** A review decision carries the `submission_count` the reviewer was shown. If the employee has resubmitted since, the decision is refused and the reviewer is asked to reload.
- **Reviewers never edit employee text.** They only add a comment and item notes.
- **History is kept.** Each submission stores a full item snapshot; every action records actor, from/to status and comment.

## Review authority

Review authority comes only from explicit reviewer assignments. Positions and units carry no supervisor, so authority is never inferred from job titles. An assignment covers an organization, a unit (optionally with sub-units) or one employee, with `effective_from`/`effective_to`. Authority is evaluated against the log's **snapshot** organization and unit, so it follows where the work was done.

Acting or delegated reviewers are ordinary effective-dated assignments; no separate delegation system exists. The assignment must be effective on the day of the decision. A reviewer appointed after a log was submitted can work the backlog, and one whose assignment has ended can no longer act. `reviewed_by` records who actually decided and is never rewritten when managers change.

Nobody reviews their own log, including Super Admin.

## Review policy and reviewer workload

How much submitted work needs a reviewer is set per organization on **Reviewer Assignments › Review policy by organization**. Admins can set it only for organizations they administer, and every change is audited.

| Mode | Waits in review queues | Final for EPMS measurement |
|---|---|---|
| Follow city-wide setting (default) | As "Manager Review Required" says | Approval if review is required, otherwise submission |
| Review every submitted day | All submitted and resubmitted days | Approval |
| No review needed | Nothing | Submission |
| Review only late and resubmitted days | Late days and resubmissions | On-time first submission; late or resubmitted days need approval |

The policy decides what waits for review and when quantities count for EPMS. It never changes a record's status, and a reviewer may still review any day they cover.

**Workload.** One reviewer covering a whole unit is a single assignment, whatever the unit's size. The page shows how many current employees each assignment covers and suggests splitting above 150, because daily review of that many people is not sustainable. Recommended practice:

- Give team leaders their own units.
- Add a second reviewer to a large unit. Whoever acts first decides, and a concurrent second decision is refused.
- For very large institutions, use "late only" or "no review needed".

There is deliberately no bulk "approve all".

## Retention

Submitted, reviewed and approved logs are never deleted through the application. There is no delete route for logs. Items are replaced only while the log is editable, and evidence can be removed only while the log is editable. Corrections go through return and resubmit, which keeps every earlier submission in history.
