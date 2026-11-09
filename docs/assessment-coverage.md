# Assessment coverage: eligibility, denominator and definitions

Every oversight number comes from `AssessmentCoverageService`. No page,
report or export computes its own version.

## Cycle and participating institutions

An `assessment_cycles` row fixes the following:

- the assessment type and period (records with that type and exact period belong to it);
- the **reference date**;
- deadlines;
- the pinned result-band policy;
- the eligibility policy switches.

Participation is explicit. `assessment_cycle_organizations` lists the
institutions taking part. An organization that merely exists is not
included. A withdrawn institution keeps its row (`status = excluded`, with a
reason) for history.

## Eligibility (the denominator)

`AssessmentEligibilityService::resolveEligibility` decides, for each employee
whose assignment on the reference date is in a participating institution.
Pending transfers do not count, and the same picker as the target resolver is
used. Rules apply in this order:

1. **Employment status** on the reference date, from status history when it covers the date and the current status otherwise. It must be in `eligible_employee_statuses` (default ACTIVE). Otherwise the employee is excluded with system reason `employment_status`.
2. **Minimum service**, only when `min_service_days` is set. The first assignment must start at least that many days before the reference date. Otherwise the employee is excluded with `new_employee`.
3. **Target group.** The form target resolver runs on the reference date and its answer (`matched`, `conflict` or `no_applicable_form`) is stored with the expected version:
   - Under `population_rule = target_rules`, an employee no form targets is excluded with `no_applicable_form`.
   - Under `all_assigned` (the default), the employee stays eligible and is reported as a blocking data-quality issue.

### Snapshot

`snapshotCycleEligibility` writes one `assessment_cycle_employee_eligibility`
row per employee. Each row holds the assignment, organization, unit,
position, status, reason, source and form resolution. It is built in chunks
and audited, and can be rebuilt while eligibility is open.

`finalize` freezes the snapshot. After that:

- the population settings of the cycle are locked;
- transfers, hires and restructures no longer move anyone. `explainEligibility` shows the frozen row next to today's live answer and flags drift;
- the denominator changes **only** through an approved exclusion.

### Exclusions (anti-gaming)

1. An institution user with `assessment_exclusions.request` asks to exclude an eligible employee, giving a reason with source `approved_exception` and a note.
2. A different user with `assessment_exclusions.approve`, inside the scope, approves or rejects it. Rejecting requires a note. Requesters cannot decide their own requests.
3. An approved exception is recorded on the snapshot row (`reason_source = approved_exception`). The employee leaves the denominator **only if both** of these hold; otherwise the employee stays eligible and counts as unassessed, outcome `approved_exception`:
   - the reason has `excludes_from_denominator`;
   - the cycle has `exclusion_reduces_denominator = true`.

Coverage is never computed as assessed ÷ currently-not-excluded with free
exclusion. The **gross coverage** figure (assessed ÷ (eligible + approved
exclusions)) is shown whenever exclusions exist, so their effect is visible.
Every change is audited, and `restoreEligibility` reverses an exception with
a note.

## Assessed and unassessed

For an eligible employee, the cycle's record gives exactly one outcome:

| Outcome | Rule |
|---|---|
| `assessed` | record status `reviewed` or `acknowledged` **and** a final percentage |
| `approved_exception` | approved exception on the snapshot row |
| `reported_unassessed` | record status `unassessed` (the institution recorded a reason) |
| `awaiting_review` | record `submitted` (all peers done, reviewer not yet) |
| `invalid_result` | reviewed or acknowledged but no percentage |
| `in_progress` | record assigned, at least one response submitted |
| `not_started` | record assigned, no response submitted |
| `not_assigned` | no record |

**Assessed** means a finalized valid result: the reviewer has signed it off.
Acknowledgement by the employee comes after finalization and is not
required. Assignment, started forms and submitted-but-unreviewed work are
never counted as assessed. Unassessed is derived, never stored, and equals
eligible − assessed, which is also the sum of the other outcomes.

## Coverage

```
Assessment coverage % = assessed ÷ eligible × 100     (bcmath, 2 dp, half up)
```

Coverage is not the same as institution workflow completion. Workflow status
is separate:

- not started, in progress, or ready for submission (derived);
- then submitted, returned, verified or finalized (stored, see [submission](assessment-institution-submission.md)).

## Gender

Gender comes from `employees.gender` at query time and is never copied into
assessment configuration. Values are bucketed case-insensitively:

- male: `male`, `m`, `ወንድ`;
- female: `female`, `f`, `ሴት`;
- blank: `unknown`;
- anything else: `other`.

Unknown and other are always reported, never dropped. For each scope the
service returns eligible, assessed, unassessed and coverage for every bucket.

Demographic figures are shown only with
`assessment_oversight.view_demographics`. When the cycle sets
`small_group_threshold`, cells with 0 < n < threshold are hidden on pages and
in exports. No official threshold exists yet (NEEDS_DECISION).

## Result bands

`assessment_result_band_policies` (versioned: draft, active, retired) and
`assessment_result_bands` define the ranges, each with its own boundary
inclusivity. Activation validates the policy and rejects it when:

- the policy has no bands;
- the policy minimum is not below its maximum;
- a band's minimum exceeds its maximum;
- a band lies outside the policy range;
- two bands overlap;
- full coverage is required and there are gaps, or the ends do not match.

Conflicts are reported, never resolved silently.

Active policies are immutable. A change is a new version, and activating it
retires the previous active version of the same code. A cycle **pins** its
policy, so historical cycles keep their classification. The pinned policy
cannot change once a submission of that cycle is finalized.

The distribution counts assessed employees per band, as a percentage of
assessed employees. It can be broken down by institution, unit, gender or
form. There is no employee ranking.

## Reconciliation

`AssessmentCoverageService::reconcile` checks:

- population = eligible + excluded;
- eligible = sum of outcomes;
- eligible = assessed + unassessed;
- eligible = male + female + other + unknown (eligible), and the same for assessed;
- sum of band counts = classifiable assessed results.

A failure is displayed as a data-consistency issue and blocks submission.

## Trend

Coverage across up to six cycles of the same type uses the same formula.
Points whose eligibility rules or band policy differ from the selected cycle
are flagged, so they are not compared blindly.
