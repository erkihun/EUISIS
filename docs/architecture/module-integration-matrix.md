# Module integration matrix

How each module uses the backbone. "Reads" lists the backbone records a module
needs; "Writes" must be "none" or a backbone command. Counts of files reading
backbone models come from the 23 October scan in
[backbone-refactor-migration.md](backbone-refactor-migration.md).

| Module | Reads from backbone | Writes to backbone | Owns | API | Events consumed | Events produced |
| --- | --- | --- | --- | --- | --- | --- |
| Cafeteria | employee, current card, organization | none | meal transactions, scans, menus, policies, ledgers, settlements | provider routes, offline sync, settlements | none yet (live verification per scan) | none |
| Transport | employee, card, assignment organization | none | routes, stops, trips, vehicles, drivers, devices, boardings, invoices, settlements | `/api/v1/transport/*` | none (an assignment observer triggers eligibility re-evaluation; becomes an event in Stage 2) | none |
| Recruitment / vacancy | position, establishment, assignment | `TransferAssignment` (internal mobility); position occupancy through the capacity service | announcements, applications, selection | none | none | `employee.transferred` (through the command) |
| Transfer | employee, assignment, position, organization | `TransferAssignment` | applications, screening, approvals, transfer records | none | none | `employee.transferred` |
| EPMS / performance | organization, unit, position, employee, assignment | none | cycles, agreements, KPIs, evidence, appraisals, results | none | none | none |
| Assessment | employee, assignment, unit, position | none | cycles, forms, responses, results | none | none | none |
| Daily work | employee, assignment, position services | none | daily logs, items, evidence, reviews | none | none | none |
| Field work | employee, assignment, organization | none | requests, approvals, participants, locations | none | none | none |
| Public feedback | organization, unit, employee (subject) | none | feedback, suggestions, complaints, routing, responses | public site | none | none |
| Grievance | employee, assignment, organization, unit | none | cases, stages, hearings, decisions, letters | none | none | none |
| Discipline / appeal | employee, assignment | none | cases, charges, decisions, appeals | none | none | none |
| Communication | employee, assignment, organization, unit | none | conversations, channels, announcements | none | none (an assignment observer syncs memberships; becomes an event in Stage 2) | none |
| Organization design | organization, unit, position, profiles | job description stamped on current assignments (moves under the backbone in Stage 2) | studies, proposals, manpower plans, imports | none | none | none |
| Situation Room | organizations, hierarchy, aggregates | none | actions and their events | none | none | none |
| Intelligence / assistant | aggregates through safe query services | none; may never change master data | alerts, forecasts, knowledge, runs | none | none | none |
| Provider portals | card verification, eligibility | none | provider users, terminals | `/api/v1/*` provider routes | none | none |
| External systems | organizations, units, positions, employee basic identity, assignment, card status, eligibility | none | their own data | `/api/v1` with scopes and organization bounds | change feed, webhooks | none |

Backbone events available to every consumer are listed in the
[event catalog](../integration/event-catalog.md).
