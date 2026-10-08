# City verification of institution assessment submissions

City verification is a governance review of an immutable institutional
snapshot. It does not permit a reviewer to change employee responses, scores,
eligibility, or exceptions.

## Workflow

1. An institution submits a readiness-validated snapshot.
2. A city reviewer with `assessment_submissions.review` starts city review.
   The system records the reviewer and timestamp and changes the status to
   `under_city_review`.
3. The reviewer either returns the submission with a required reason or
   verifies it with `assessment_submissions.verify`.
4. A different authorized finalizer may finalize a verified snapshot.

`submitted -> under_city_review -> verified -> finalized`

At any review point a reviewer may return the submission for correction. A
returned, rejected, or outdated submission is never overwritten: a later
submission gets the next revision number.

## Controls

- Permission and organization scope are enforced by the service, not the UI.
- The submitting user cannot start city review, verify, or finalize their own
  submission. The verifier cannot finalize the same submission.
- Before verification and finalization, the stored source fingerprint is
  compared with live aggregate source markers. Changed data marks the
  submission `outdated`; it must be resubmitted.
- Return and reject actions require a comment. Every transition creates a
  submission event and an audit record.
- Review users receive no score-editing authority from this workflow.

## Deadlines and notifications

When a cycle has a `verification_deadline`, the daily
`assessments:oversight-monitor` job sends scoped city verifiers a due-soon or
overdue notification for submitted or under-review summaries. Due-soon uses
the cycle's configured `reminder_days_before`; the system does not invent a
threshold. Each submission is reminded at most once per day.

## NEEDS_DECISION

The following are policy choices, so the software deliberately does not
invent them:

- which position(s) may sign an institution submission;
- whether an institution sign-off must be repeated after correction;
- whether a city rejection has a distinct policy meaning from return;
- whether finalization can ever be reopened and by whom.
