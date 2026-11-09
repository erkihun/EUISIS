# Assessment evaluator workflow

## Dynamic form

The evaluator page is rendered from the record's published form-version definition: sections, criteria, rating options, descriptions, comment mode, evidence mode and option-score visibility. No UI is tied to a particular form code. The employee context is the stored assessment snapshot, not the employee's current placement.

Evaluators select configured rating options only. The browser submits option identifiers plus permitted comments; it never submits an authoritative score. Required criteria, required comments and required evidence are checked on submit. A partial draft is allowed and is protected with `lock_version` optimistic concurrency control.

## Submission and scoring

Submission first saves the draft, retaining it if validation fails. In one database transaction the server validates the assignment, cycle and form snapshot, resolves rating-option scores, persists score snapshots, calculates the evaluator result with `AssessmentScoringService`, and writes audit data. It never trusts raw totals, percentages or contribution values from the browser.

`AssessmentResultCalculator` waits until every configured evaluator slot is submitted. It averages same-type evaluators using the configured aggregation, applies evaluator component weights, and applies the form contribution weight separately. The reviewer either finalizes that calculated result or returns a component for correction. The final record freezes its resolved result band and is the only state exposed to oversight and EPMS.

## Anonymity and employee access

An anonymous evaluator remains traceable internally for controlled audit purposes, but their identity is withheld from ordinary reviewer and employee views. My Assessments exposes only the employee's own finalized/acknowledged results, component summaries allowed by policy, and acknowledgement. It never exposes draft responses, internal review notes, other evaluator comments or anonymous identities.

## Decisions still owned by policy

The organization must define the late-submission policy per cycle and, where needed, direct-manager reporting data and appeal rules. Assessment execution deliberately does not infer behavioural ratings from daily activity, attendance, service transactions or system usage.

