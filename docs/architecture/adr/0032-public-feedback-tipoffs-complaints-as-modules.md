# ADR 0032: Service feedback, Tip-offs and public complaints as separate modules

- Status: proposed (awaiting the owner's go-ahead on the data split, step 3)
- Date: 2026-11-08
- Deciders: product owner (requirement), engineering (design)

## Context

The owner asked (2026-11-08) that public service feedback, Tip-offs (ጥቆማ) and
public complaints each "stand on their own as modules — a real modular
monolith". Today they are one module, `Modules/Feedback` (108 PHP files, about
12,000 lines), and one table, `employee_service_feedback`, with a `type`
column. Their workflow, assignment, routing rules, referrals, subjects,
attachments, status history and metrics are shared code over that table. The
menus, dashboards, forms and references are already separate per type
(2026-11-07/08); the code and the data are not.

ADR 0016/0017 define a module: its own `Modules/<Name>` layers, its own tables
(listed in `Modules/ownership.php`), and other modules reach it only through
its `Contracts` (enforced by the architecture tests). A module that shares a
table with two others is not one.

## Decision (proposed)

Four modules, each owning its data:

| Module | Owns | Publishes |
| --- | --- | --- |
| `PublicIntake` (what stays of `Modules/Feedback`) | employee feedback tokens, institution QR codes, the public page shell and form token, rate limits, the receipt, Code Rule references, the public tracking page, intake settings, the questionnaire | `PublicSubmissionHandler` (each type module registers one: its rules, how it stores a submission, its tracking stage), QR and target contracts that exist today |
| `ServiceFeedback` | ratings, comments, feedback kind and service point, questionnaire answers; its list, dashboard, reports | `ServiceFeedbackRecord`, feedback metrics for the Situation Room, Intelligence and Performance |
| `PublicTipOffs` | Tip-offs and their form details, assignments, status history, attachments, subjects, referrals, routing rules; dashboard, reports | `TipOffRecord`, Tip-off metrics |
| `PublicComplaints` | complaints and their form details, the same case-handling records, routing rules; dashboard, reports | `PublicComplaintRecord`, complaint metrics |

The case-handling code that Tip-offs and complaints share (workflow,
assignment, routing, referral) becomes a small engine in each of the two
modules, or one platform helper parameterised by the module's own tables —
never one table for both.

### Steps (each deployable and green on its own)

1. **Contracts first, no data change.** Every outside reader (Situation Room
   panels, Intelligence analyses, the attention wall, production readiness,
   Core relations, Performance KPI actuals) moves to type-specific contracts.
   `PublicSubmissionHandler` is introduced in `Modules/Feedback`.
2. **Code split on the current table.** Create the three type modules; each
   gets its own model scoped to its type (`type = …`) and its own controllers,
   pages, routes, permissions and tests. `Modules/Feedback` becomes
   `PublicIntake`. The ownership test lists the shared table as owned by
   `PublicIntake` with the three type modules as declared writers, temporarily.
3. **Data split (needs the owner's go-ahead).** Three tables
   (`public_service_feedback`, `public_tip_offs`, `public_complaints`) plus
   each module's own child tables (attachments, assignments, status history,
   referrals, subjects, answers). A migration copies rows by type keeping their
   ids and references, checks the counts, and switches the models; the old
   table stays read-only for one release, then is dropped. On the live MySQL
   server DDL is not transactional, so the migration is guarded and resumable
   and is rehearsed on a copy of the production data first.
4. **Clean-up.** Remove the temporary writers and the old table; ownership and
   architecture tests strict.

## Consequences

- Each type can change its form, workflow, permissions and data without
  touching the others; a fault in one does not reach the others.
- Shared reporting (the Situation Room's "community voice", the all-submissions
  overview, exports across types) becomes a composition of three contracts.
- Step 3 changes live data and needs a rehearsal on a production copy, a
  backup before deploy, and a maintenance window for the copy.
- Routing rules that today apply to "any type" are copied into both the
  Tip-off and the complaint rules.
- The pilot scope freeze (docs/ROADMAP.md) does not cover this: it is
  restructuring, not a new module, but it is large — roughly one to two weeks
  of work across the four steps.
