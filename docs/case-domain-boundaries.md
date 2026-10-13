# Case-domain boundaries

EUIIS maintains four separate legal and operational domains:

| Domain | Record of authority | Current implementation | Must never become automatically |
| --- | --- | --- | --- |
| Employee discipline | `disciplinary_cases` and its workflow records | Regulation 201/2018 discipline: charge, committee/investigator, response, evidence, recommendation, head decision, delivery (docs/employee-discipline.md). A grievance or reviewed public complaint may be the recorded `source` of a new case; the source record is never converted. | An employee grievance or public complaint |
| Employee grievance | `grievances` and its related workflow records | Authenticated employee self-service, committee routing, decisions, evidence and correspondence. | A public/service-recipient complaint or a disciplinary case |
| Employee appeal / Tribunal | `grievance_appeals`; `administrative_tribunal_cases` for configured Tribunal referral | Appeal is linked to the issued grievance decision. Tribunal access is case-role and organization scoped. | An overwrite of the original decision or grievance |
| Public service feedback | `employee_service_feedback` with `type` `feedback`, `suggestion`, or `complaint` | QR-token-resolved public submissions and dedicated review inboxes. | A grievance, disciplinary case, punishment, or performance action |

`employee_service_feedback` is intentionally a shared technical storage abstraction for the three public submission types. Its type, permissions, review routes, reference numbers and reports are separate from employee grievance and discipline semantics.

The `grievance_committees` table is a shared committee registry across committee types. Legal composition is **not** interchangeable: `App\Services\Legal\StatutoryCommitteeRules` enforces the Regulation 201/2018 Art. 5 (disciplinary) and Art. 30 (grievance) composition, with `selection_source` and `term_number` on each member, and applies to those two statutory types only — never to EPMS or Tribunal panels.

The Regulation 201/2018 source file is in this repository (`docs/ደንብ_ቁጥር_201_2018_….pdf`). Article-level enforcement and the remaining NEEDS_DECISION / NEEDS_DATA points are traced in `docs/regulation-201-2018-traceability.md`.
