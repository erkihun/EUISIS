# ADR 0032: Service feedback, Tip-offs and public complaints as separate modules

- Status: accepted (owner, 2026-11-08: "all four steps")
- Date: 2026-11-08
- Deciders: product owner (requirement), engineering (design)

## Context

The owner asked (2026-11-08) that public service feedback, Tip-offs (ጥቆማ) and
public complaints each "stand on their own as modules — a real modular
monolith". They were one module, `Modules/Feedback` (108 PHP files, about
12,000 lines), and one table, `employee_service_feedback`, with a `type`
column. Menus, dashboards, forms and references were already separate per type
(2026-11-07/08); the code and the data were not.

ADR 0016/0017 define a module: its own `Modules/<Name>` layers, its own tables
(listed in `Modules/ownership.php`), and other modules reach it only through
its `Contracts` (enforced by the architecture tests).

## Decision

Four modules:

| Module | Owns | Publishes / implements |
| --- | --- | --- |
| `Feedback` — the public intake and case handling | employee feedback tokens, institution QR codes, the public pages, form tokens, rate limits, receipts, the questionnaire, the tracking page; the case-handling records of every type (assignments, status history, attachments, answers, subjects, referrals) and the routing rules; the read-only view `employee_service_feedback` | `PublicSubmissionTypes` (the registry), `PublicCaseDashboard` (scoping and case counts for a type's dashboard), the extension point `Contracts\Extensions\PublicSubmissionType`, and its earlier contracts |
| `ServiceFeedback` | `public_service_feedback`; the rating, kind of feedback and window served at; Code Rule PSF | implements `PublicSubmissionType` |
| `TipOffs` | `public_tip_offs`; the Tip-off form (confidentiality, suspect, violations, evidence); Code Rule PTO; its dashboard; tracking by reference | implements `PublicSubmissionType` |
| `PublicComplaints` | `public_complaints`; the complaint form; Code Rule PSC; its dashboard; tracking by reference and phone | implements `PublicSubmissionType` |

How they fit:

- A type module defines its type: the requirements of the public form, its own
  validation rules and the columns it stores, its table, its Code Rule and how
  the public may track it. It registers that with `PublicSubmissionTypes` from
  its provider. Feedback never imports a type module (a test enforces it).
- Feedback's submission model writes each row to the table its type's module
  registered, and reads all three through the view, so reports, the Situation
  Room and the Intelligence analyses keep one shape. A type's own pages
  (dashboards) read its own model.
- The view cannot cascade: deleting a submission deletes its case records in
  the model.

### Steps taken

1. **Outside readers through contracts.** Already the case: other modules use
   Feedback's contracts and read models; the declared SQL readers read the
   view.
2. **Code split.** The three type modules were created; the type-specific form
   classes, rules, stored columns, Code Rule collision checks and dashboards
   moved into them; the intake's policy, requests, storage and tracking ask the
   registry instead of naming types.
3. **Data split** (migration `2026_11_08_140000`). The three tables are created
   with the old columns, rows copied by type with their ids and references,
   counts checked; the child foreign keys to the old table are dropped (ids are
   UUIDs); the old table is kept as `employee_service_feedback_legacy`; the view
   takes the old name. Rehearsed on MariaDB with a copy of the local data,
   including rollback and re-run.
4. **Clean-up** (migration `2026_11_08_150000`). The legacy table is dropped
   only after every one of its rows is found in a type table; otherwise it is
   kept and the migration stops.

## Consequences

- Each type's form, data and dashboard change in its own module; a missing
  module is an explicit error ("no module registers the type").
- A future migration that adds a submission column adds it to the three tables
  and recreates the view (as the step-3 migration's `createView()` does).
- Raw SQL that writes `employee_service_feedback` no longer works (it is a
  view): writes go through the model or the type's table (the scale tool and the
  demo seeder were changed this way).
- Rolling back step 3 is possible until step 4 has run.
