# Organization feedback analytics

How the structured answers of the organization feedback form
([organization-public-feedback-form.md](organization-public-feedback-form.md))
reach the Executive Situation Room. The Situation Room itself is unchanged:
two panels were added to the existing Public Voice analytics
(`app/Services/SituationRoom/Analytics/PublicVoiceAnalytics.php`), and they use
the same scoped base query as every other Public Voice panel.

## 1. Panels

Each of the three Public Voice categories (Feedback, Tip-offs, Complaints)
gets the same two panels. A question can apply to any of the three types.

| Panel key | Type | Shows |
|---|---|---|
| `{p}_question_ratings` | stacked bar, one row per rating question | how many answers fell on each point of the 1–5 scale |
| `{p}_question_choices` | table | per single-choice, multiple-choice and yes/no question: each answer, how many chose it, and the share of those who answered that question |

`{p}` is `feedback`, `tip_offs` or `complaints`. With nothing to show, each
panel is a `NO_DATA` notice, never a row of zeros.

Not aggregated, ever: short and long text answers, the narrative, the
submitter's name, phone, email or address, and evidence.

## 2. What is counted

```
employee_service_feedback f          ← PublicVoiceAnalytics::base()
   type = this category
   status != hidden
   scope->apply(f.organization_id)    ← hierarchy roll-up / isolation
   view filters (target type, status, category, office, …)
   submitted_at within the period
 ⋈ public_feedback_answers a ON a.submission_id = f.id
   grouped by a.question_code (and rating / option / yes-no)
```

- **Scope and roll-up.** The answers inherit the submissions' scope. A
  woreda viewer sees that woreda's answers. Its sub-city and its bureau see
  them rolled up. A sibling sub-city never does. Tested in
  `tests/Feature/SituationRoom/SituationRoomQuestionAnalyticsTest.php` for
  all three types.
- **Target vs handler.** `f.organization_id` is the target institution (the
  scanned QR). Routing and assignment (`public_feedback_assignments`) are not
  read, so who handles a submission never moves its answers.
- **Across versions.** Answers are grouped by the question's stable `code`.
  The label shown is that of the newest published or retired version that
  asks the code; drafts are never read. A submission's own detail screen
  still shows the wording of the version it answered
  (`PublicFeedbackQuestionnaire::presentAnswers`). Rewording a question keeps
  its code, so the panel follows it. Changing what a question means needs a
  new code.
- **Hidden** submissions are left out, as in every Public Voice panel.

## 3. Statistics policy

- **Rating questions:** a distribution over the five labelled points only.
  The points are ordered labels ("Very dissatisfied" … "Very satisfied"), so
  the panel draws no per-question average. The existing `average_rating` KPI
  on Feedback (the overall rating column) is unchanged.
  `PublicFeedbackAnswerStatistics::ratingSummary()` still returns an average
  for report consumers. Showing it in the Situation Room is a
  NEEDS_POLICY_DECISION.
- **Choice questions:** counts and the share of those who answered the
  question. A multiple-choice question's shares can add up to more than 100%,
  and the panel description says so.
- **Trend per question:** not drawn. Choosing a summary to trend (mean, or the
  share answering 4–5) is a policy choice (NEEDS_POLICY_DECISION). The
  period filter already compares periods.
- **No inference.** Nothing is derived from free text: no sentiment, no
  topic classification.

## 4. Service, category and type analytics

| Analysis | Source | Organization form behaviour |
|---|---|---|
| By service | `COALESCE(position_service_id, service_id)` | a catalog service chosen on the form counts; a service described only in words (`service_text`) does not (SERVICE_LINKAGE_REQUIRED) |
| By category | `category_id` | unchanged; the form offers the applicable categories |
| Feedback rating | `rating` (overall satisfaction, 1–5) | the form's required overall question writes this column |
| Tip-off type | `tip_type` | unchanged |
| Status / outcome | `status`, `resolved_at`, `closed_at` | unchanged |
| By organization | `organization_id` rolled up | the scanned institution |

## 5. Reconciliation

For one scope and period, `feedback_received + tip_offs_received +
complaints_received` equals the number of non-hidden public submissions whose
`organization_id` is in scope. All three categories use the same base and
differ only by `type`. Tested in
`SituationRoomQuestionAnalyticsTest` ("reconciles Feedback, Tip-offs and
Complaints…").

## 6. Historical organization context

A submission keeps the institution it was given about
(`organization_target_id`, `organization_id`, target snapshots). Which
**ancestor** rolls it up follows the **published** hierarchy version
(`organization_closure_paths`), the Situation Room's documented
current-structure view. If a woreda moves to another sub-city, its past
feedback rolls up under the new parent; the row itself never changes (tested).
An "as at submission" view would need the hierarchy version stored with the
submission: NEEDS_POLICY_DECISION.

## 7. Not a performance measure

These figures describe services and institutions as visitors reported them.
They are not written to, or read by, EPMS, employee scores, salary,
discipline or promotion. Nothing in this integration writes to any record.

## 8. Payload and performance

The panels return aggregates only. In the local PostgreSQL measurement, the
Feedback page's deferred payload was 8.1 KB. The four answer queries took
0.8–4.4 ms, and the slowest queries on that page were permission loading,
not analytics. Answers are aggregated in SQL (`GROUP BY question_code, …`)
and labels are read in two queries for the codes on screen, so there is no
N+1. Indexes used: `pfa_submission_idx` (join), `pfa_code_rating_idx`,
`pfa_option_idx`. No index was added in this phase: the local data volume is
too small for a meaningful plan (NEEDS_DATA, see the production-readiness
document).
