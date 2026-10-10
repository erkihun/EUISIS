# Grievance Investigation Committee

The committee that examines an employee grievance under Addis Ababa City
Administration Regulation No. 201/2018, Art. 30. It is legally distinct from
the Disciplinary Committee even though both share the committee tables
(`CommitteeType::Grievance` vs `CommitteeType::Disciplinary`).

## Statutory composition (Art. 30)

A grievance committee has exactly **five voting members**:

- **1 Chairperson**, appointed by the institution head
  (`selection_source = appointed`).
- **3 members**, elected by employees (`selection_source = elected`), of whom
  **at least one must be a woman**.
- **1 HR professional**, who is a member and serves as **secretary**. In this
  system the secretary is the `writer` committee role
  (`selection_source = hr_representative`).

Selection sources are the `GrievanceCommitteeSelectionSource` enum, whose
values match `App\Services\Legal\StatutoryCommitteeRules`.

## Where it is enforced

`StatutoryCommitteeRules::compositionProblems()` encodes the full rule and is
invoked from `GrievanceCommitteeService::approve()` for grievance committees.
Approval is refused with a localized problem list when any of these hold:

- the committee does not have exactly five serving members;
- a member has no recorded selection source;
- the chair is missing or not appointed by the head;
- the HR secretary (writer) is missing;
- the appointed/elected counts are not 1 and 3;
- none of the elected members is a woman (an **unknown** gender does **not**
  satisfy the rule — it surfaces the problem rather than passing silently);
- a member exceeds two terms or a two-year term length.

Problem codes render through `grievances.committee_rules.*` in both English and
Amharic.

## History preservation

Membership is never deleted. Ending a term sets `effective_to` / `end_reason`
and marks the row inactive, so the committee that served on any past date can
always be reconstructed (`scopeServingOn`). Terms keep `term_number` and
effective dates.

## Qualification that cannot be verified

Where the data to confirm a member's eligibility is absent,
`StatutoryCommitteeRules::eligibility()` returns NEEDS_DATA rather than
declaring the member eligible. The system never fabricates eligibility.
