# ADR 0030: One Grievance Directorate, and the court is reached only from it

- Status: accepted
- Date: 2026-11-07
- Deciders: product owner (the rule), engineering (implementation)

## Context

The owner stated the grievance ladder (2026-11-07): "in this system there is only one
Grievance Directorate. From the different institutions a case goes from the committee to
the team, and from the team to the directorate. If this directorate does not resolve it,
or the complainant is not satisfied with the directorate's answer, the complainant may
appeal to the court. So the case can cross to the court only from the Grievance
Directorate; from the team or the committee it cannot go straight to the Administrative
Court."

Until now any route could send a case to an external authority (the Administrative
Tribunal/Court) from any stage, and every issued decision letter told the employee they
could appeal to the Administrative Court within 30 days, opening the Art. 38(1) clock.

## Decision

1. **One Grievance Directorate.** At most one active designation of kind `directorate`
   exists (ADR 0029 designations). Designating or reactivating a second one is refused.
2. **The court only from the directorate.** A route whose target is an external authority
   must start from the Grievance Directorate (route form and validation). The routing
   service never offers such a target from any other stage, and creating a stage at an
   external authority is refused unless the previous stage is the Grievance Directorate —
   so a route written before this rule cannot bypass it.
3. **Directorate decision opens the court clock.** Only a decision issued at the Grievance
   Directorate records the Art. 38(1) 30-day statutory deadline and prints the
   Administrative Court notice. A committee or team decision letter instead tells the
   employee to appeal in My Portal to the next level, and that the court comes after the
   directorate.
4. **Directorate overdue.** When the Grievance Directorate's stage is past its due date with
   no issued decision, the employee may appeal from My Portal straight to the court along
   the directorate's employee-appeal route to the court. Such an appeal has no appealed
   decision (`grievance_appeals.appealed_decision_id` is now nullable; the unique index is
   kept). One such appeal per directorate stage.

## Consequences

- Administrators must configure an `employee_appeal` route from the Grievance Directorate
  to the Administrative Court for either court path to work.
- Committee and team decisions no longer start the statutory court deadline in the system.

## Legal note (recorded, not resolved here)

Regulation 201/2018 Art. 38(1) gives the employee 30 days from the written decision of the
head of the institution to appeal to the Administrative Court, and Art. 36 covers a
decision not given in time. This ADR follows the owner's procedure, which places the
Team and Directorate before the court; whether an employee keeps a direct right of appeal
against the institution head's decision is a legal question for the owner's legal adviser.
The system does not prevent an employee from going to the court outside it.
