# Institution submission, sign-off and city verification

Implemented by `AssessmentInstitutionSubmissionService`.

```
Institution ──validate──▶ SUBMITTED ──city review──▶ VERIFIED ──▶ FINALIZED
                              │  ▲                        │
                              │  └── resubmit ◀── RETURNED ◀┘ (return, with reason)
                              └──▶ REJECTED (with reason) ─── resubmit
     any open submission whose source data changes ──▶ OUTDATED ── resubmit
```

## Readiness (pre-check)

`validateReadiness(cycle, organization)` must pass every check before a
submission:

| Check | Meaning |
|---|---|
| `participating` | The institution is included in the cycle |
| `eligibility_finalized` | The denominator is frozen |
| `has_population` | The institution has employees in the snapshot |
| `no_blocking_issues` | No BLOCKING data-quality issue for the institution (see [data quality](assessment-data-quality.md)). This covers missing assignments, unfinalized assessments without a recorded reason, form conflicts, missing evaluators, invalid scores, pending exclusions and exclusions without a reason. |
| `totals_reconcile` | Every reconciliation check holds |
| `no_open_submission` | No submission is already submitted, verified or finalized |

Warnings and information-level issues do not block.

## Submitting

The actor needs `assessment_submissions.submit` and the institution inside
their scope. The institution row is locked, readiness is re-run inside the
transaction, and the service stores a snapshot:

- **Counts:** eligible, excluded, assessed and unassessed, plus male, female and unknown/other, each as eligible and assessed. Coverage percentage.
- **`summary_snapshot`:** the full totals (outcomes, gender with coverage), the band counts with the pinned policy, and the unclassified count.
- **`source_fingerprint`:** sha256 of the totals, the band counts and raw change markers (count and maximum `updated_at` of records, eligibility rows and responses for the institution), read uncached.

Institution users never type totals. A new submission gets the next
`revision_no`, so history is kept, and each action adds an
`assessment_submission_events` row and an audit entry.

Sign-off authority is currently permission plus scope. Restricting it to a
Position (HR Head, Institution Head) through configuration is NEEDS_DECISION.

## City review

| Action | Permission | From | Notes |
|---|---|---|---|
| Return for correction | `assessment_submissions.return` | submitted, verified, outdated | Reason required. Submitter notified. |
| Reject | `assessment_submissions.return` | submitted | Reason required. Submitter notified. |
| Verify | `assessment_submissions.verify` | submitted | The submitter cannot verify. Outdated check first. |
| Finalize | `assessment_submissions.finalize` | verified | The submitter and the verifier cannot finalize the same revision. Outdated check first. |

Reviewers are never given score-editing rights by this workflow.

A **finalized** submission is immutable: no further action is accepted. If
its source changes later, the snapshot stays untouched and the institution
page warns "changed after finalization" for investigation.

## Outdated detection

`detectOutdatedSubmission` recomputes the fingerprint. For a submitted or
verified summary that no longer matches, it:

- sets the status to `outdated` (OUTDATED / REQUIRES_RESUBMISSION);
- records an event and an audit entry;
- updates the institution's `submission_status`.

It runs before verify and finalize, when the institution page is opened, and
daily in `assessments:oversight-monitor`, so detection never depends on page
views. Totals shown under a signed-off submission are always the snapshot,
never silently live figures.

## Deadlines and reminders

`submission_deadline` and `verification_deadline` come from the cycle; no
duration is invented. The institution table shows `overdue`, `due_soon` and
`met`. `due_soon` appears only when `reminder_days_before` is configured.

The daily monitor sends:

- institution reminders for overdue (and, if configured, due-soon) submissions, at most once per institution per day;
- a daily digest to verifiers when submissions await verification;
- a notice to the submitter on return or rejection, sent immediately.
