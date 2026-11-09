# ADR 0029: Grievance Teams and Directorates are designated units with named, signed members

- Status: accepted
- Date: 2026-11-07
- Deciders: product owner (requirement), engineering (design, decisions below taken as defaults under the delegated-decisions rule)

## Context

The owner asked (2026-11-07): "in Grievance Management, do not create new bodies:
call up the existing Team and Directorate, give the employees in that team and
directorate their Regulation 201/2018 responsibilities, and once they are named,
the signatures of the team's and the directorate's members must be attached"
("እንዳዲስ መፍጠር ሳይሆን ቡድን እና ዳይሬክተር ጠርተን … በደንብ 201/2018 መሰረት ሀላፊነት
ብንሰጣቸው … ፊርማቸው መያያዝ አለበት").

Until now a Team or Directorate stage (`handler_type = organization_unit`) was
handled by anyone HR had placed in that unit (or a unit beneath it) who held the
right permission. Nobody was named, no responsibility was recorded, and no
signature was attached, unlike committee members (ADR 0028).

## Decision

1. **Designation, not creation.** A Grievance Team or Grievance Directorate is an
   existing `organization_units` row that an administrator *designates*
   (`grievance_handler_units`: unit, its organization, kind `team` |
   `directorate`, status). No unit, organization or employee is created. A unit
   is designated at most once; a designation is deactivated, never deleted.
2. **Named members with a responsibility.** Members are employees whose current
   active assignment is in the designated unit or a unit beneath it
   (`grievance_handler_unit_members`). Each term has one responsibility:
   - `head` (Team leader / Director): receives the cases routed to the unit,
     distributes them, leads the review and signs and submits the report and
     recommendation to the deciding authority (Regulation 201/2018 Arts. 34(3), 35(1));
   - `expert`: examines the application and evidence and drafts the findings for the head (Art. 34).
   (Owner correction, 2026-11-07: a team has a team leader and experts, the directorate a
   director and experts; there is no secretary. Earlier `investigator`/`secretary` terms
   were converted to `expert`.)
   Exactly one serving `head` per unit. Terms are ended, never deleted, and can be
   corrected in place while serving (same rules as committee terms).
3. **Signature at appointment (same as ADR 0028).** A member is registered
   together with a signature image (PNG/JPG/WebP ≤ 1 MB, private `local` disk,
   `employee-signatures/`). It becomes the employee's `signature_path` and is
   snapshotted on the term with its SHA-256, who recorded it and when. The unit
   page flags members without one, and a designated unit whose serving members
   lack signatures is reported as a problem.
4. **Eligibility.** The Art. 6(2) rule applied to committee members also applies
   here: an employee disciplined in the last two years is not named.
5. **Who handles a designated unit's cases.** Once a designated unit has at least
   one serving member, only its serving members are its staff: the `head` is the
   assigning officer (with `grievances.assign`), every member sees the unit's
   cases (with `grievances.view_assigned`, when unit staff see all cases), and
   case officers are chosen among them. A unit that is not designated, or has no
   serving member yet, keeps the earlier HR-placement rule, so existing routes and
   open cases keep working while units are being set up.
5a. **Ending a term records why** (owner request 2026-11-07): transferred to another
   institution, transferred to another work unit, retired, dismissed, or other (with a
   note). A serving member whose current assignment is no longer in the unit (or who is no
   longer active) is flagged on the page and stops handling the unit's cases at once,
   before the term is formally ended.
6. **Routes use designated units only.** The route form offers Grievance Team and Grievance
   Directorate as separate choices, and a source may be "All institutions", "All committees" or "All teams"
   (`source_all`, `source_unit_kind`); a route from one specific handler is preferred over
   an "all" route. A route from institutions may target "All committees", which is scoped
   (`target_all`): each grievance goes to its own institution's committee, never another's.
   A route from committees may target "All teams", also scoped: the designated team in the
   committee's institution, else in the nearest parent institution (two at that level: none). A new or edited route whose source or
   target is a Team/Directorate is refused unless that unit is an active
   designation; the route form offers the designated units directly. Each route
   row shows whether its Team/Directorate target is ready (designated, head named),
   and the routes page counts unready targets. Existing routes are not rewritten:
   one into an undesignated unit keeps working under the HR-placement fallback
   and is flagged until the unit is designated.
7. **Permissions.** No new permission is introduced: the designation and its
   members use the committee permissions (`grievance_committees.view` /
   `.manage`, `grievance_committee_members.manage`), which are held by exactly the
   roles that configure grievance handling bodies. This avoids changing the
   105-role catalog (ADR 0026) for the same duty.

## Consequences

- Teams and Directorates now carry named people, responsibilities and signatures,
  so letters and reports from a unit stage can show who acted.
- Administrators must designate each Team/Directorate and name its members
  before the stricter rule applies; until then the HR-placement fallback is used.
- The legal position of a Team/Directorate decision is unchanged by this ADR:
  Regulation 201/2018 Part Four names only the committee, the head of the
  institution and the Administrative Court. Whether a unit's output is a binding
  decision or a recommendation to the head remains an owner/legal question.
