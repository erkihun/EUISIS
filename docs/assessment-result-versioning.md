# Competency-assessment result versioning

`assessment_result_versions` is the authoritative immutable history for a
finalized `assessment_record`. Finalization creates version 1 (`original`). A
later authorized change creates a new row with a reason, reference, actor,
and `supersedes_version_id`; it never overwrites the original score.

The unique nullable `current_key` makes exactly one row current per assessment
on PostgreSQL and SQLite. Valid non-original change types are appeal decision,
moderation, and technical correction. Controllers must use
`AssessmentResultVersionService`, not update score columns directly.

The historical form-version and score breakdown on the assessment record are
retained as the recalculation source. Submission snapshots are immutable; a
later revision must be reconciled through the existing submission-drift flow.
