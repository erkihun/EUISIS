# Public submissions — identity and field rules

Amharic: የሕዝብ ጉዳዮች — የማንነት እና የመስክ ደንቦች

Three public submission types share one table (`employee_service_feedback`),
one workflow and one policy matrix: `App\Services\ServiceFeedback\PublicSubmissionPolicy`.

| | Feedback (አስተያየት) | Tip-off (ጥቆማ) | Complaint (ቅሬታ) |
|---|---|---|---|
| Internal type | `feedback` | `suggestion` | `complaint` |
| Full name | **required** | optional | **required** |
| Phone number | **required** | optional | **required** |
| E-mail | optional | optional | optional |
| Submitter organization | optional | — | optional |
| Address | — | optional | — |
| Anonymous | **no** | **yes** | **no** |
| Main text | comment **required** (with a 1–5 rating) | facts **required** | details **required** |
| Tip-off type | — (refused) | **required** | — (refused) |
| Service received | **required** for an employee target | not required (optional context) | optional |
| When / where | — | optional | optional |
| Evidence (photo/video/audio/PDF) | — | optional | optional |
| Reference | none | `PTO-…` | `PSC-…` |
| Recorded identity mode | `identified` | `optional` | `identified` |

- Category is optional everywhere and must apply to the type and target.
- The phone is stored in `client_contact`. Rows from before this change may
  hold an e-mail there; that is historical data and left alone.
- Anonymous Feedback and anonymous Complaints are refused for new submissions.
  Historical anonymous rows are untouched.
- What each type asks is fixed policy. The city settings can still switch a
  type off and switch evidence off; they cannot make Feedback or Complaint
  anonymous.

## Creation (`PublicFeedbackSubmissionService`)

1. Resolve the QR token to the target on the server, and resolve it again on
   POST.
2. Validate against the policy: identity, Tip-off type, narrative, evidence.
3. Check the category and service against the resolved target.
4. Consume the one-time form token.
5. In one transaction: create the row with the target and assignment
   snapshot, `intake_channel = WEB_QR`, `submitted_at`; issue the reference;
   store evidence privately; record the first status; route (or mark
   UNROUTED); audit by codes only.
6. After commit: notifications and an optional e-mail acknowledgement.
7. Storage or database failure rolls everything back, deletes stored files,
   and tells the citizen to try again. Nothing half-saved remains.
