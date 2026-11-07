# ADR 0031: Disciplinary committees are set up in Discipline Management

- Status: accepted
- Date: 2026-11-07
- Deciders: product owner (requirement), engineering (design, permission mapping taken as a default under the delegated-decisions rule)

## Context

Disciplinary and grievance committees share one table (`grievance_committees`,
`grievance_committee_members`), and both were set up on the Grievance
Management committees page by choosing a type. On 2026-11-07 the owner removed
the type from that page ("only grievance committees are set up here") and then
asked that Disciplinary Cases management stand on its own like Grievance
Management. Without a home of their own, disciplinary committees could no
longer be created.

## Decision

1. **Own pages.** Discipline Management gets "Disciplinary committees"
   (`/discipline/committees`, routes `discipline.committees.*`) in its sidebar
   group. It is the same committee board, registration page and committee page
   (`GrievanceCommitteeController`, `Grievances/Committees/*`), told apart by the
   route name: the server passes `domain` and `route_prefix`, and every link stays
   inside its own module.
2. **Each page shows its own committees.** The discipline pages list and open
   disciplinary committees only and register every new committee as
   disciplinary. The grievance pages list and open every other type and
   register grievance committees only. Opening a committee through the other
   module's pages is a 404.
3. **Permissions follow the committee type** (`CommitteePermissions`), in the
   controller and in `GrievanceCommitteeService`. No new permission is added
   (ADR 0026 catalog unchanged):

   | Action | Disciplinary committee | Other committees |
   | --- | --- | --- |
   | View | `discipline.view` | `grievance_committees.view` |
   | Create, edit | `discipline.manage` | `grievance_committees.manage` |
   | Members | `discipline.manage` | `grievance_committee_members.manage` |
   | Approve | `discipline.decide` | `grievance_committees.approve` |

   Approval by `discipline.decide` follows Regulation 201/2018 Art. 5 (the head
   of the institution sets up the committee); the creator still cannot approve
   their own committee. Disciplinary committees still start active on creation,
   as before this ADR, so approval applies only to one left pending.
4. The employee and work-unit pickers accept `discipline.manage`, so a case
   officer can name members.
5. **No configurable routes for discipline.** Regulation 201/2018 fixes the path
   inside one institution (investigator or committee, head's decision, delivery,
   Administrative Court), so there is no routes page. Only the committee is
   pre-selected when a formal case gets its panel: the complete committee
   serving the accused's work unit, else the one serving all staff; none when
   that is not a single committee. The officer still chooses.

## Consequences

- Grievance administrators no longer manage disciplinary committees; the
  Discipline Case Officer and Disciplinary Decision Authority roles do.
- Existing disciplinary committees are unchanged and now appear only under
  Discipline Management.
- Composition rules, signatures (ADR 0028) and the member history work the
  same on both pages.
