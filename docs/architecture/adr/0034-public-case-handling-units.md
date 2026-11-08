# ADR 0034: Handling units for public Tip-offs and complaints

- Status: accepted
- Date: 2026-11-08
- Owner request: "we must be able to set up the public Tip-off and complaint
  unit: pick it from the institution's work units and assign, then attach a
  signature for each member."

## Decision

1. A routing administrator (`service_feedback.manage_routing`, within their
   institution scope) designates one of an institution's **existing** work
   units as a handling unit (`public_case_handling_units`) **for one case
   type**: Tip-offs or complaints. Owner follow-up (same day): public Tip-offs
   are received by their own separate unit, so each type has its own units
   (one designation per unit and type). Nothing is created in the
   organization structure. A unit can be deactivated, never deleted.
2. Members (`public_case_handling_unit_members`) are the unit's own staff:
   an active employee placed in the unit or a unit beneath it. Each has a
   responsibility (**head** or **officer**; one serving head), dates, an
   optional appointment-letter reference and a **required signature image**
   (PNG/JPG/WebP up to 1 MB) kept on the private disk with its SHA-256, who
   recorded it and when. A replaced signature is a new file; a term is ended
   with a reason, never deleted.
3. Once an institution has an active handling unit for a type, its cases of
   that type can be assigned (by routing rule or by hand) only to that type's
   handling units; the handler pickers list only those. A rule for both types
   may name a unit designated for either, and routing then skips it for the
   other type. Institutions without one keep the previous behaviour (any of
   their units).
4. A case assigned to a handling unit reaches the unit's serving members
   (notifications, "assigned to me" lists and dashboard counts), not every
   employee placed in the unit.
5. The page warns when a unit has no members, not exactly one head, a member
   no longer placed in the unit, or a member without an active user account.

6. Each module has its own unit page and address, never a shared one (owner
   follow-up, same day): `/feedback-admin/units` (service feedback,
   `service-feedback.units.*`), `/tip-off-admin/units` (Tip-offs,
   `tip-offs.units.*`) and `/complaint-admin/units` (complaints,
   `public-complaints.units.*`). The type comes from the address; a unit is
   reachable only through its own module's address. The short-lived
   `/feedback-admin/handling-units`, `/feedback-admin/tip-off-units` and
   `/feedback-admin/complaint-units` redirect.
7. The Feedback Unit records the unit that receives service feedback and its
   members with signatures. Rating feedback is reviewed, not routed, so it
   changes no assignment; Tip-off and complaint pickers never list it.

## Decisions taken as defaults

- Same permission as routing rules; no new permission.
- Two responsibilities (head, officer), mirroring the grievance teams of ADR
  0029 but without Regulation 201/2018 eligibility checks, which apply to
  grievance bodies only.
- Implemented inside `Modules/Feedback` through Backbone contracts
  (UnitDirectory, EmployeeDirectory, AssignmentContextReader); it does not
  reuse the Grievance module's code.
