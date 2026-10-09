# Assessment Form Builder

Administrators build competency, behavioural, leadership and custom assessment forms in the UI under **Performance › Assessment Forms**. No paper form's content is written into code. A supplied form, such as a professional, employee or director/team-leader assessment, is entered as configuration and can be cloned and versioned.

The builder extends EPMS rather than running beside it:
- A criterion may reference a catalog competency (`competencies`).
- A version may classify its result with an EPMS result scale (`performance_rating_scales`, type `RESULT`).
- The builder uses the EPMS permission conventions, organization scope and audit log.

## Concepts

| Concept | Table | Notes |
|---|---|---|
| Assessment type | `assessment_types` | Editable reference data. Behavioural competency, technical competency, leadership, peer assessment and custom are seeded; they are not the only valid types. |
| Form | `assessment_forms` | Code, names, type, owner (city-wide or one organization), status (active or archived), current published version. |
| Form version | `assessment_form_versions` | `draft` → `published` → `superseded`/`archived`. Holds scoring, contribution weight, period type, result scale, acknowledgement and review switches, and effective dates. |
| Section | `assessment_form_sections` | Title (EN/AM), description, configured maximum, optional weight, order, required. |
| Criterion | `assessment_criteria` | Title and description (EN/AM), optional catalog competency, maximum score, optional weight, required, comment mode and evidence mode (disabled, optional or required). |
| Rating option | `assessment_rating_options` | Label and description (EN/AM) and a **decimal** score (3, 2, 1, 0.5 …). Two to twenty per criterion. |
| Target rule | `assessment_form_target_rules` | Which employees a version applies to (below). |
| Evaluator scheme | `assessment_evaluator_schemes` | Evaluator type, how many, contribution weight, selection method, aggregation, anonymous, needs review. |

Scores and weights are `NUMERIC`, never float.

## Versions

- **Draft:** one draft per form at a time. A draft is edited and saved as a whole, and it is used by no assessment.
- **Publishing:** validates the draft (below), makes it immutable, and marks the previously published version `superseded`. The new version becomes the form's current version.
- **New version:** copies the latest version into a new draft. Editing it never touches the published one.
- **Clone:** creates an independent form whose draft v1 copies another form's content.
- **Archive:** stops offering the form, while its versions stay for history.
- **Discard:** deletes a draft. A form's only version cannot be discarded.

## Publishing validation

A version cannot be published unless:
- it has a name and at least one section, and every section has criteria;
- every criterion has at least two rating options, none negative;
- a criterion's configured maximum equals its best option, so the maximum is reachable and no option exceeds it;
- a section's configured maximum equals the sum of its criteria maxima;
- the form's configured total equals the sum of its section maxima;
- criterion weights within a section are all set and sum to 100, or none are set;
- the weighted method has a weight on every section, summing to 100;
- the contribution-weight method has a contribution weight, which lies in (0, 100];
- there is at least one evaluator type, and with several types, every type has a weight and the weights sum to 100;
- there is at least one include target rule, and each rule names its target;
- the effective-to date is on or after effective-from.

**Validate** reports the problems without publishing. Publishing runs the same checks inside a locked transaction.

## Target groups

A rule names master data:
- **By id:** position, occupation, organization, organization unit (optionally including sub-units).
- **By value:** grade level, job family.
- **Everyone.**

A rule is `include` or `exclude`, has a priority, and may have effective dates. Administrators write no expressions.

`AssessmentTargetResolver` decides which published version applies to an employee:
1. Use the assignment valid on the reference date: never the current placement, never the employee's choice.
2. A version applies when an include rule matches and no exclude rule does.
3. Among applicable versions of the same assessment type, the highest priority wins. A tie is a **conflict**, and nothing is chosen.
4. No applicable version is **no applicable form**, never a guess.

The **assignment preview** on the builder counts, within the viewer's scope and for a chosen date, how many employees each published form of the type reaches, how many match none, and how many conflict. It runs in chunks of 1,000 employees.

An organization's form can target only that organization's positions, units and organization; occupations, grades and job families are shared.

## Importing a form

`php artisan assessments:import-forms [files…] [--publish] [--actor=<email>]`
creates forms from definition files instead of typing them into the builder.
A definition is data: `{ "form": {…}, "version": <the builder's draft payload> }`.
`AssessmentFormImporter` validates it with the same rules as the builder's save
request, then creates the form through `AssessmentFormService`, so it is
validated, versioned and audited like a form made in the UI.

- **New forms only:** a form whose code already exists is skipped and never
  changed. Once imported, administrators own it.
- **Drafts by default:** a form is published only with `--publish`, and only
  when it passes the publishing checks. The command lists what still blocks
  publication.
- **Default files:** with no file arguments, it imports the official 6-month
  behavioural competency forms in `database/seeders/data/assessment-forms/`.

| Code | Paper form | Criteria | Total | Contribution |
|---|---|---|---|---|
| `BCA-01-PROFESSIONALS` | ቅጽ. 01, professionals | 11 | 30 | 5% |
| `BCA-02-STAFF` | ቅጽ. 02, staff | 10 | 30 | 5% |
| `BCA-03-DIRECTORS-TEAM-LEADERS` | ቅጽ. 03, directors and team leaders | 12 | 30 | 5% |

Each is a six-month, percent-of-maximum form with a 5% contribution, scored by
four peers whose results are averaged. Review and employee acknowledgement are
on. Wording and scores are as supplied; numbering is normalized (1, 1.1 …).
They have **no target rules**: nothing in master data identifies professionals,
staff or team leaders, so an administrator sets the targets and publishes.

## Printed form

The form preview (**Official form**) and an assessment record's **Print** use
the official paper layout (`OfficialAssessmentSheet`):

- a header with the work unit, employee, position, grade and assessment period;
- a numbered table with each criterion, its weight and rating levels, and one
  score column per evaluator;
- the total row and signature lines for the assessed employee and the
  immediate supervisor.

It prints black on white, landscape. An evaluator's printout shows only their
own column. The reviewer and managers of the organization see every submitted
column. Columns are anonymous: ordered by assignment, labelled 1, 2, 3 …,
never by evaluator. The assessed employee's printout shows the final result
instead of per-evaluator columns.

## Security

- **Permissions** (category Performance Management):
  - `assessment_forms.view`, `assessment_forms.create` (also clone), `assessment_forms.edit_draft` (also new version);
  - `assessment_forms.publish`, `assessment_forms.archive`.
- **Grants:**
  - Performance Officer: view, create, edit draft.
  - Organizational Admin: all five, within scope.
  - City-level roles: all.
- **Scope** (`AssessmentFormPolicy`):
  - A city-wide form is visible to every holder of the view permission and managed only by unrestricted administrators.
  - An organization's form is visible and managed only within that organization's scope.
  - Master-data lookups are scoped the same way.
- **Audit:** created, draft saved (with a before/after structural summary: titles, maxima, scores, targets, evaluators), validated, published, version created, cloned, archived, draft discarded. Long descriptions are not copied into the audit log.

## Delivery stages

1. **Done:** form builder, versioning, validation, scoring engine, target rules and resolution, assignment preview, admin pages, permissions, audit.
2. **Next:** assessment cycles, evaluator assignment (self, direct manager, peer …), employee assessments and responses with score snapshots, workflow (in progress → submitted → reviewed → finalized), acknowledgement, queued and idempotent assignment generation, EPMS contribution service.
3. **Then:** reports (completion, results by organization, unit, position, form and cycle, result bands, unassessed with reasons), My Portal "My Assessments", evaluator inbox, printable form, exports.

## NEEDS_DECISION

| Question | Current behaviour |
|---|---|
| Who may approve and publish forms? | `assessment_forms.publish`: Organizational Admin within scope, and city-level roles. |
| Managerial classification as a target | No such field exists on positions. Target managers by position, job family or grade until one is defined. |
| How is the direct manager determined? | Planned for stage 2: the EPMS agreement's manager, else explicit reviewer coverage. Never inferred from job titles. |
| Peer selection and anonymity | Configurable per version (manager-, system- or admin-selected; anonymous or not). There is no random selection. |
| Several evaluators of one type | Averaged (`average`), the only method so far. |
| Rounding and display precision | Stored to 4 decimals and shown to 2. Intermediate values keep 10. |
| How assessment results combine with work-plan results in EPMS | Not defined. Results will be exposed through a service; EPMS scoring is unchanged. |
| Official forms: who each applies to | Imported without target rules. Set them per form (position, job family, grade …) before publishing. |
| Official forms: peers per employee and who selects them | Imported as four peers (the paper form's four score columns), chosen by the manager, results averaged. |
| ቅጽ. 03, criterion 6 (ethics) | Levels 6.3 and 6.4 both score 0 in the supplied form, while ቅጽ. 01 scores the same wording 1. Kept as supplied until confirmed. |
| Competency name "ራህሮታዊ ብስለት" | Kept as supplied. Its levels describe emotional maturity ("ስሜታዊ ብስለት"); confirm the intended wording. |
