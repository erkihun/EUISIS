# Assessment execution

Assessment execution extends the existing form builder and oversight records; it does not create a second assessment engine. A cycle first takes a finalized eligibility snapshot. Assignment generation is then queued and fans out in chunks of 500 rows. Each row pins the resolved `assessment_form_version_id` and the employee assignment snapshot used for organization, unit, position and grade.

Generation is retry-safe: the assessment-record uniqueness rule and response uniqueness rule prevent duplicate employee or evaluator assignments. Forms are read only from their pinned version; a later published version never changes an in-flight or historical record.

## Assignment and access

An assessment record contains one evaluator response per evaluator. Self and direct-manager slots are resolved by `AssessmentEvaluatorResolver`; peer and other named slots are filled by an authorized reviewer. Peer selection is institution-bound at the assessment reference date. Reassignment cancels the previous response, requires a coded reason, and leaves an evaluator-change audit record.

The evaluator workspace starts at the signed-in user's response assignments. It has no employee search. Opening, draft saving, evidence upload, conflict declaration and submission all require the relevant permission, the matching evaluator assignment, an editable response state and a live record.

## Lifecycle

`pending_assignment → assigned → submitted → reviewed/finalized → acknowledged` is the record lifecycle. Evaluator responses use `not_started`, `in_progress`, `submitted`, `returned`, `conflict_declared` and `cancelled`. A returned response keeps a revision snapshot; a finalized record is immutable unless a permitted reviewer reopens it with a reason.

Cycles must be active to accept submission. Late handling is explicit on the cycle: `block`, `allow_with_reason`, or `allow_flagged`. A null policy remains a product decision and records a late submission without silently blocking it.

## Scale and privacy

The generation coordinator dispatches chunk jobs, and notifications are batched by user. Evaluator and reviewer lists paginate in SQL. Immutable form definitions can be cached by version, but evaluator answers and employee results are never globally cached. Evidence uses the private `local` disk and is downloaded only after evaluator or scoped detailed-results authorization.

