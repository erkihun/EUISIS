# Legacy Organization Baseline — UAT scenarios

Companion to [organization-design-legacy-baseline-plan.md](organization-design-legacy-baseline-plan.md)
and [organization-design-uat.md](organization-design-uat.md). Each scenario names the
accounts (default roles), the steps and what must be true. Automated
equivalents are listed per scenario.

Roles: **Designer** = Organization Designer (scoped), **Approver** =
Organization Design Approver (scoped), **Catalog** = Design Catalog Manager.
The Designer and the Approver must be two different people.

## Scenario A — legacy bureau with an existing mandate and structure

1. Catalog records the city-level proclamation in **Legal Sources** (no
   organization) and attaches the gazette PDF.
2. Designer records the bureau's own establishment regulation as a source,
   attaches it, and opens **Legacy Baselines › Open baseline** for the
   bureau with the date the structure was in force.
3. The wizard shows 22 steps. Steps whose records already exist (mandate,
   units, positions) read *Complete* with their counts; the others read
   *Incomplete*. Each capture step links to the ordinary page filtered to
   the bureau.
4. Designer edits the mandate and picks the regulation as its legal
   source; on the baseline, picks it as primary source and the hierarchy
   version that places the bureau.
5. Designer runs **validation**: errors block, warnings list the incomplete
   steps. Fixes, re-runs, **submits**.
6. Approver **returns** with a reason → baseline back to draft with the
   comment; Designer fixes and resubmits.
7. Approver **approves** with a minute number, then **publishes**. The
   snapshot is frozen (counts, SHA-256), the organogram shows the units,
   expand/collapse and print work.
8. Editing the published baseline is refused; **New revision** drafts v2.

Must hold: the Designer cannot approve or publish (button absent; server
refuses); another bureau's designer gets 403 on every URL.
Automated: `LegacyBaselineTest`, `LegalSourceTest`, `HardeningTest`.

## Scenario B — institution with sub-city offices only

Same as A; the **Unit design** step counts the sub-city offices. The
**Structure version** step reads complete only when the hierarchy version
places the institution; otherwise the Designer marks it by hand with a note.

## Scenario C — sub-city and woreda offices

As B; the snapshot's organogram nests woreda offices under sub-city
offices. Publishing a later revision after an office is renamed and
another added, then **Compare with another published baseline**, lists the
added office, the renamed one under *Changed*, and the relationship
changes. Automated: `DesignOverviewTest` (comparison).

## Scenario D — institution with no lower offices

The Designer marks **Unit design** *Complete* by hand with the note "no
lower offices". A later refresh keeps the hand-set status. Automated:
`LegacyBaselineTest` (hand-set step survives refresh).

## Scenario E — functional reporting differs from structural reporting

A sub-city public service office is structurally under the sub-city and
functionally reports to the bureau. Both relationships are recorded on the
unit (existing structure module); the snapshot keeps both, and the
comparison lists a change of either one under *Structural and functional
relationships*.

## Import UAT

| Case | File | Expected |
|---|---|---|
| Valid Excel | mandates.xlsx, 500+ rows | Read in chunks; every row *Valid*; commit creates draft mandates |
| Invalid Excel | missing `effective_from` column | Batch *Failed*, missing columns named |
| Duplicate row | same code twice | Second row *Invalid* "appears again in this file" |
| Missing required code | blank `code` | Row *Invalid* "code is required" |
| Bad reference | function with unknown `mandate_code` | Row *Mapping required*; commit blocked until mapped |
| Existing code | code already in the organization | Row matched, not duplicated |
| Re-run | same file twice | Second commit creates nothing; matched by code / legacy code |
| Unsafe file | .exe | Refused at upload |
| Excel dates | serial date cell | Read as YYYY-MM-DD |

Automated: `DesignImportTest`.

## Document suggestions

Paste the powers-and-functions article of a proclamation on a mandate's
page. With the AI gateway on, suggestions list each function with an
*Uncertain* mark where ambiguous; **Apply** asks for a code and records the
function; **Reject** records nothing. With the gateway off the result is
*Assistant unavailable* and capture continues by hand. Automated:
`DesignIntegrationTest`.

## As of a date

On **Organization Design › Overview**, pick an organization and a date:
the mandate version in force then, the published baseline that described
it, the approved plan covering the date and the job descriptions in force.
Automated: `DesignOverviewTest`.
