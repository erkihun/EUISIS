# Organization public feedback form

Amharic: የተቋም የሕዝብ አስተያየት ቅጽ

This document covers the digital form a visitor opens by scanning an
institution's feedback QR code (`/feedback/{token}`). It digitizes the
institution's paper service-feedback questionnaire into the existing Public
Feedback domain. It is not a new domain: the form uses the same submission
table, the same three types, the same identity policy, routing, workflow,
notifications and receipt as before.

Related documents: [public-feedback.md](public-feedback.md),
[public-submission-rules.md](public-submission-rules.md) (the identity matrix),
[organization-feedback-qr.md](organization-feedback-qr.md),
[public-tipoff-form.md](public-tipoff-form.md),
[situation-room-public-voice-analytics.md](situation-room-public-voice-analytics.md).
Validation and release: [organization-feedback-form-uat.md](organization-feedback-form-uat.md),
[organization-feedback-form-security.md](organization-feedback-form-security.md),
[organization-feedback-analytics.md](organization-feedback-analytics.md),
[organization-feedback-production-readiness.md](organization-feedback-production-readiness.md).

> **Source note.** The paper form was described in the requirements, but no
> image of it was attached in this implementation session. The mapping in §1
> comes from the concepts the requirements list. **Check it against the
> official paper form before sign-off.** Wording on the paper that is unclear
> was not copied. Proposed wording is marked NEEDS_LANGUAGE_REVIEW in §10.

## 1. Paper form → digital form

| Paper concept | Digital form | Stored as |
|---|---|---|
| Client / respondent information | Step "About you and review" (identity fields from the policy matrix) | `client_name`, `client_contact` (encrypted), `client_email` (encrypted), `submitter_organization` |
| Service received | "Service received": the institution's **published service catalog**, or free text when it has none | `service_id` (+ `service_no_snapshot`, `service_name_snapshot`), or `service_text` |
| Office / service point | "Office or service point", shown only when the institution has two or more offices | `organization_unit_id` |
| Date / time of service | "Date of service" (Gregorian in English, Ethiopian in Amharic) and a rough time (morning / afternoon / evening) | `service_date` (Gregorian `date`), `service_time_band` |
| Service-experience rating rows | Configurable **rating questions**, five-point scale | `public_feedback_answers.rating_value` |
| Overall satisfaction | "Overall, how satisfied are you with the service?" (Feedback, required) | `rating` (the existing 1–5 column) |
| Open comments | "Your comments about the service" (Feedback) | `comment` |
| Suggestions / what should be improved | Configurable long-text question (`improvement` in the proposed template) | `public_feedback_answers.text_value` |
| Complaint / problem description | "What happened?" (Complaint) | `comment` (the narrative column) |
| Desired outcome | Configurable long-text question (`desired_resolution`) | `public_feedback_answers.text_value` |
| Office use: received by, reference, date received, action taken | Not asked. The system records `submitted_at`, `reference_number`, `intake_channel = WEB_QR`, routing and assignment, status history | system columns and workflow tables |

Not collected: national ID, date of birth, religion, ethnicity, political
affiliation, health information. No demographic fields (gender, age range,
residence) are collected either: none is approved by policy
(NEEDS_POLICY_DECISION, §11). If policy approves one, add it as an optional
questionnaire question (single choice) rather than as a column.

## 2. Submission types

The three canonical types are unchanged. The form adapts its steps to the
type, and the route always states the type on the server.

| Step | Feedback (አስተያየት) | Tip-off (ጥቆማ, internal `suggestion`) | Complaint (ቅሬታ) |
|---|---|---|---|
| 1 | Service information: service, **relevant work unit**, date, time | Tip-off details: type, facts, more details, evidence | Service information: service, **relevant work unit**, date, time |
| 2 | Service experience + **overall satisfaction (required)** | Related service (optional): service, **relevant work unit** | Complaint details: what happened, where, evidence |
| 3 | Your feedback: comments + detail questions | Service experience *(only if a question applies)* | Service experience *(only if a question applies)* |
| 4 | About you and review | About you (optional) and review | About you and review |

The progress line reads "Step 2 of 4 · Service experience". It shows no
percentage. A step that has nothing to ask does not appear.

**Tip-off, not "suggestion".** The internal type `suggestion` has been the
Tip-off (ጥቆማ: suspected corruption or improper practice) since
`2026_10_14_200000_add_public_tip_off_fields`. This form keeps that meaning.
Improvement recommendations ("what should be improved") belong to Feedback,
as a questionnaire question. If a separate "improvement suggestion" type is
wanted, that is a policy decision (NEEDS_POLICY_DECISION, §11). The
questionnaire can already attach questions to the `suggestion` type.

## 3. Fields and validation

Every field is validated on the server. The page mirrors the rules but never
enforces them alone.

| Field | Types | Rule |
|---|---|---|
| `service_id` | all | optional; a UUID of a **published, in-force** service of the **scanned** institution (checked after the token is resolved) |
| `service_text` | all | optional, ≤ 200 characters; kept only when no `service_id` is chosen |
| `organization_unit_id` | all | the **relevant work unit**: an active, in-force unit of the scanned institution flagged *Available for public feedback*; re-checked on POST against the resolved institution. Stored with name/type snapshots. See [public-feedback-unit-selection.md](public-feedback-unit-selection.md) |
| `unit_selection_mode` | all | `SELECTED` (a unit) or `GENERAL` ("General / Not sure", no unit row). Tip-off and Complaint must answer while the institution offers units; a post without the key is treated as `GENERAL` |
| `service_date` | Feedback, Complaint | optional, `Y-m-d`, not in the future, within the last year |
| `service_time_band` | Feedback, Complaint | optional; `morning`, `afternoon` or `evening` |
| `questionnaire_version` | all | the version the page showed; refused if another version is published now ("the form was updated"). Required when the type now has a **required** question: a page opened before that version was published states none, and is refused rather than skipping a question the visitor never saw. With only optional questions, a page that showed none is accepted and the row records no version |
| `answers.{code}` | per question | see §4; an answer to a question this version does not ask, or answers with no version, are refused |
| `rating` | Feedback | required, 1–5 (overall satisfaction) |
| `comment` | Feedback | required, ≤ 2000 (policy matrix) |
| `message` | Tip-off, Complaint | required, ≤ 5000 |
| `tip_type`, `incident_date`, `involved_parties`, `submitter_address` | Tip-off | as in [public-tipoff-form.md](public-tipoff-form.md) |
| `incident_location` | Tip-off, Complaint | optional, ≤ 255 |
| `attachments[]` | Tip-off, Complaint | optional; ≤ 5 files, ≤ 75 MB in total; images, video, audio, PDF ([public-evidence-security.md](public-evidence-security.md)) |
| identity fields | all | the fixed matrix in [public-submission-rules.md](public-submission-rules.md) |

On the employee QR routes, `service_id`, `service_text`, `service_date`,
`service_time_band`, `questionnaire_version` and `answers` are `prohibited`. A
`organization_unit_id` posted there is ignored, like any forged target field.

Field names are the same in the browser and in Laravel: the form posts
exactly the column names above, through a single Inertia `useForm`.

## 4. Questionnaire (configurable questions)

### Model

| Table | Purpose |
|---|---|
| `public_feedback_question_sets` | one row per **version** of the questionnaire (`code` = `ORGANIZATION_SERVICE_FEEDBACK`, `version_no`, `status` draft / published / retired). At most one published version per code (partial unique index `pfqs_one_published`). |
| `public_feedback_questions` | `code` (stable across versions), `section` (`experience` / `details`), `question_type`, `label_en` / `label_am`, `help_en` / `help_am`, `is_required`, `submission_types` (JSON), `sort_order` |
| `public_feedback_question_options` | choices of a single- or multiple-choice question: `code`, `label_en`, `label_am` |
| `public_feedback_answers` | one row per answer; one row per chosen option of a multiple-choice question |

The question types are `rating`, `single_choice`, `multiple_choice`,
`short_text` (≤ 255), `long_text` (≤ 2000) and `yes_no`. This is not a form
builder: there is no branching, no conditional logic, and no per-institution
questionnaire (NEEDS_POLICY_DECISION if one is wanted).

### Rating scale

Every rating, overall or per question, uses the platform's existing
five-point satisfaction scale. Feedback already used it before this form:

| Value | English | Amharic |
|---|---|---|
| 1 | Very Dissatisfied | በጣም አልረካሁም |
| 2 | Dissatisfied | አልረካሁም |
| 3 | Neutral | መካከለኛ |
| 4 | Satisfied | ረክቻለሁ |
| 5 | Very Satisfied | በጣም ረክቻለሁ |

Because an existing approved scale is reused, no new scale was invented
(RATING_SCALE_POLICY_REQUIRED does not arise).

### Versioning

- A **draft** is edited and saved whole. No submission refers to a draft.
- **Publishing** freezes the draft and retires the previously published
  version, in one transaction. Publishing is refused unless every question
  and option has Amharic wording, and every choice question has at least two
  options.
- A **published or retired** version is never edited or deleted: answers
  reference it (`restrictOnDelete`). To change wording, create a new draft
  (a copy of the published version), edit it and publish it. Keep the same
  `code` when you only reword a question, so analytics can follow it across
  versions.
- **Withdrawing** the published version leaves the form with no
  questionnaire. The core fields keep working.
- Every submission records the version it was shown
  (`employee_service_feedback.question_set_id`), even if the visitor answered
  none of its optional questions. Staff screens show each answer in the
  wording of that version.
- Each answer stores the question's `code` and `type` alongside the ids.

### Administration

**Public feedback → Feedback questionnaire** (`/feedback-admin/questionnaire`)
requires `service_feedback.manage_questions` and a city-level (unrestricted)
account, because the questionnaire is city-wide. **New draft** starts from
the published version. If nothing was ever published, it starts from the
proposed template (`PublicFeedbackQuestionnaireTemplate`). That template is
a proposal for review, not approved policy. Nothing reaches the public until a
person publishes it. Each action is audited.

## 5. Organization QR target

```
QR → /feedback/{token} → PublicFeedbackTargetResolver::resolveOrganizationToken
   → active code + active organization → TargetContext → page
POST /feedback/{token}/{type} → token resolved AGAIN → service/office checked
   against that organization → one-time form token consumed → submission
```

- The page never offers a choice of institution. `organization_id`,
  `organization_target_id`, `target_type` and `employee_id` posted by a
  browser are ignored. Tests prove the stored target is the scanned
  institution.
- A QR disabled, revoked or rotated after the page opened, or an institution
  no longer `active`, sends the POST back to the generic page and stores
  nothing. Unknown, tampered, disabled and archived cases all render the same
  "unavailable" page.
- No internal organization id is sent to the page. Service and office ids
  are catalog ids, sent so the visitor can choose among them. The logo is
  served by the token (`GET /feedback/{token}/logo`, 404 for any token that
  does not resolve), not by its `/storage/organizations/logos/{id}/…` path,
  which carried the organization id.
- The validation, the controller and the submission service read the same
  published questionnaire once per request (`PublicFeedbackQuestionnaire` is
  bound `scoped`), so a version published mid-request cannot validate the
  answers against one version and store them against another.
- Large catalogs: when an institution has more than 150 published services,
  the page gets a search box. `GET /feedback/{token}/services?q=` searches
  that institution's catalog only (throttled, `no-store`). An unknown token
  gets an empty list, the same as no match.

## 6. Submission, routing, notification, receipt

`PublicFeedbackSubmissionService` remains the one creation path. Inside one
transaction it creates the row with the target and service snapshots, writes
the structured answers, issues the reference (`PSC-…` for a Complaint,
`PTO-…` for a Tip-off; Feedback has none, per the policy matrix), stores
evidence privately, records the first status, and routes the submission (or
marks it `unrouted` when no rule matches). Notifications run after commit,
and their failure never rolls back a saved submission. A storage or database
failure rolls everything back and deletes any files stored.

Double submission is prevented by the disabled button while sending, the
one-time form token, and a 120-second duplicate fingerprint. The fingerprint
covers the service information and the answers.

The receipt shows the type, the institution, the reference and the
submission time (localized calendar). There is **no public tracking**
([public-feedback.md](public-feedback.md) gap 5). The receipt asks the
visitor to keep the reference; it does not expose any content.

## 7. Mobile, accessibility, errors

- A single column (`max-w-md`), section cards, 16 px controls, touch targets
  of 44 px and up, and a fixed action bar with **Back** and **Next / Send**
  above the QR action dock.
- Ratings are a `fieldset` and `legend` with five **native radio inputs**.
  Each has its full text label for screen readers ("4 — Satisfied") and a
  numbered target. The chosen answer is written out under the row. Selected
  colours keep 4.5:1 contrast. Ratings are never stars alone.
- Choice questions use native radios and checkboxes inside fieldsets. Errors
  are tied to their field with `aria-describedby` and `role="alert"`.
- "Next" checks the step's required fields. A server 422 opens the step
  holding the first error, scrolls to it and focuses it. A form-wide refusal
  (replayed form, questionnaire updated, storage trouble) appears at the top
  of every step.
- Each failure says what it is: 413 (files too large), 419 (page expired,
  reload), 403, 429 (too many sends) and 5xx each have their own message.
  The server turns a 419 on these routes into a redirect back with a
  page-expired notice. No stack trace reaches the visitor.
- **Draft.** Feedback and Complaint keep a draft of the non-personal fields
  in `sessionStorage` for the open tab, and clear it once the submission is
  sent. The draft never holds identity fields or files, and a Tip-off keeps
  no draft at all.

## 8. Privacy

Nothing beyond the policy matrix is collected. Identity fields stay hidden
from staff without `service_feedback.view_submitter_identity`. The IP address
and user agent are kept for abuse investigation only and are never shown.
Answers and service information appear on the staff detail screens
(`organizationForm`) for Feedback, Tip-off and Complaint.

## 9. Situation Room and reporting

- **Average satisfaction** comes from `employee_service_feedback.rating`
  (unchanged), so existing panels include the new form's Feedback
  automatically.
- **By service**: the community-voice "by service" panels now count the
  catalog service (`service_id`) as well as an employee's position service.
  A service given only in words (`service_text`) is unlinked and is not
  counted there; it remains visible on the submission (SERVICE_LINKAGE_REQUIRED
  for that institution).
- **Question level** (in the Situation Room since the validation phase):
  `{feedback|tip_offs|complaints}_question_ratings` (1–5 distribution per
  rating question) and `…_question_choices` (answered, chosen, share per
  choice). Both are built on the same hierarchy-scoped, filtered, in-period
  base as every Public Voice panel, so roll-up and sibling isolation are
  identical. Details: [organization-feedback-analytics.md](organization-feedback-analytics.md).
  `PublicFeedbackAnswerStatistics::ratingSummary()` / `choiceCounts()` remain
  for reports, filtered by institution, service, type and period. Hidden
  submissions are left out everywhere. Indexes: `pfa_submission_idx`,
  `pfa_code_rating_idx`, `pfa_question_rating_idx`, `esf_service_idx`,
  `esf_question_set_idx`.
- **By relevant work unit**: `CommunityVoiceQuery::byUnit()` and
  `PublicFeedbackMetrics::byUnit()` count organization-form submissions per
  unit the visitor named, under the name recorded at submission, with one
  "General / Not sure" bucket. Employee-QR rows are not counted there.
- Category, complaint-category and tip-type analysis use the existing columns.
- These are what visitors said about a **service or an institution**. They
  are not a measure of any individual employee and must not be used as one.
  Nothing here triggers discipline.

## 10. Language

All new strings exist in English and Amharic (`resources/js/i18n/*/serviceFeedback.ts`,
`lang/*/service-feedback.php`); the strict i18n audit passes. English shows
Gregorian dates; Amharic shows Ethiopian dates (the shared calendar
components). The database stores Gregorian dates.

The questions, categories, institution name and validation messages are
written by the server. A first visit (a scanned QR) reaches the server before
the browser's language is in the `euisis_locale` cookie, so the server wrote
them in its default language while the screen showed Amharic. The page now
compares the shared `textLocale` prop with the language on screen and reloads
once when they differ (`LocaleContext`).

NEEDS_LANGUAGE_REVIEW (proposed wording; review with the language owner):

| Key / text | Proposed Amharic |
|---|---|
| Step progress `stepOf` | ደረጃ {current} ከ{total} |
| `stepSubmit` "About you and review" | ስለ እርስዎ እና ማረጋገጫ |
| `serviceTime` "Approximate time" and the bands | ግምታዊ ሰዓት — ጠዋት / ከሰዓት በኋላ / ምሽት |
| `servicePoint` "Office or service point" | ቢሮ ወይም የአገልግሎት መስጫ |
| `overallSatisfaction` | በአጠቃላይ በአገልግሎቱ ምን ያህል ረክተዋል? |
| `questionnaireStatus_retired` "Withdrawn" | ከአገልግሎት የወጣ |
| Template question wording (all items in `PublicFeedbackQuestionnaireTemplate`) | e.g. የሠራተኞች ትሕትና እና አክብሮት, አገልግሎት ለማግኘት የጠበቁበት ጊዜ, ምን መሻሻል አለበት? |

## 11. Decisions still needed

| Code | Decision |
|---|---|
| NEEDS_POLICY_DECISION | Approve, change or reject the proposed questionnaire items before publishing version 1. |
| NEEDS_POLICY_DECISION | Whether any demographic question (gender, age range, residence) is approved. None is collected now. |
| NEEDS_POLICY_DECISION | Whether a separate "improvement suggestion" type is wanted beside Tip-off (§2). |
| NEEDS_POLICY_DECISION | Which organization units each institution flags as *Available for public feedback* (`is_public_feedback_selectable`). Offices and former institution offices are flagged by default; internal units are opt-in. |
| NEEDS_POLICY_DECISION_PUBLIC_FEEDBACK_UNIT_REQUIREDNESS | Tip-off and Complaint must answer "unit or General / Not sure" while units exist; Feedback may stay general. Confirm or extend. |
| NEEDS_POLICY_DECISION_PUBLIC_UNIT_HIERARCHY_SOURCE | Units are not version-tied; the public form groups them by the structural `parent_unit_id`. Confirm this is the public-facing structure. |
| NEEDS_POLICY_DECISION | Public tracking of a reference (still not built), and whether Feedback should get a reference number. |
| SERVICE_LINKAGE_REQUIRED | Institutions without a published service catalog receive free-text services only. |
| NEEDS_POLICY_DECISION | Per-institution questionnaires (not built; one city-wide questionnaire). |
