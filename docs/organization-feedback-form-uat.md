# Organization feedback form: UAT

User acceptance scripts for the organization QR feedback form
([organization-public-feedback-form.md](organization-public-feedback-form.md)),
and the record of the validation run on 17 October 2026.

## 1. Before you start

1. **Publish the questionnaire.** Public Feedback › Feedback questionnaire ›
   New draft (starts from the proposed template) › review every question and
   its Amharic wording › Publish. The template is a proposal, not approved
   policy (NEEDS_POLICY_DECISION).
2. **Enable the institution's QR.** Organization › Feedback QR › Enable QR.
   Print it or copy the link.
3. **Optional, for the service list:** publish the institution's service
   catalog in Organization Design. Without one, the form asks for the service
   in the visitor's own words.
4. Use a phone, or a browser at phone width (320, 375, 390, 430 px), in a
   private window, so you are a first-time visitor.

## 2. Scripts

Mark each step Pass or Fail. Record any failure with a screenshot and the
time.

### A. Feedback (English)

| # | Step | Expected |
|---|---|---|
| A1 | Scan the QR (open the link) | Institution name and logo; three choices: Feedback, Tip-off, Complaint. No choice of institution. |
| A2 | Choose Feedback | "Step 1 of 4 · Service information" |
| A3 | Type or choose the service; pick a date and "Morning"; Next | Step 2. The date shows in the Gregorian calendar. |
| A4 | Press Next without the overall rating | "Please choose your overall satisfaction." The error is focused. |
| A5 | Rate several questions (try the keyboard: Tab, then arrow keys) and the overall question | The chosen label shows under each row ("Satisfied"). |
| A6 | Next; write a comment; answer "Did you get the service?" | Step 3 → Step 4 |
| A7 | Enter name and phone; review; Send | Receipt: "Feedback submitted successfully", the institution, the date and time. No reference (Feedback has none by policy). |
| A8 | Staff: open the submission | The service, the date, the time band and each answer in the questionnaire's wording. |

### B. Complaint (Amharic, with evidence)

| # | Step | Expected |
|---|---|---|
| B1 | Open the link in a new private window, choose ቅሬታ | All text in Amharic, **including the questions** (for example "የመጡበትን አገልግሎት አግኝተዋል?"). |
| B2 | Open the date picker | Ethiopian calendar (for example ጥቅምት 2019). |
| B3 | Describe what happened; attach one photo | "1 ፋይል ተያይዟል" in the review |
| B4 | Name and phone; Send | Receipt with a reference `PSC-…` and the date in the Ethiopian calendar. The receipt does not say who handles it. |
| B5 | Staff: open the complaint | Status "unrouted" if no routing rule matches, otherwise assigned. The evidence opens for authorized staff only. |

### C. Tip-off (anonymous)

| # | Step | Expected |
|---|---|---|
| C1 | Choose ጥቆማ / Tip-off | Tip-off type first; no questionnaire unless one applies |
| C2 | Choose a type, write the facts, open "more details" and pick an incident date | Ethiopian picker in Amharic; Gregorian in English |
| C3 | Leave every identity field empty; Send | Accepted. Reference `PTO-…`. |

### D. Refusals

| # | Step | Expected |
|---|---|---|
| D1 | Disable the QR in admin, then send a form that is already open | Nothing stored; the generic page opens |
| D2 | Open an unknown link `/feedback/` + 64 × `a` | "Unavailable" page, the same as for a disabled QR |
| D3 | Send the same form twice quickly | One submission |
| D4 | Leave the page open longer than the session lifetime (120 minutes by default), then send | A "page expired" notice on a fresh copy of the page, not a server error and not "session expired" |
| D5 | Publish a new questionnaire version while a visitor's form is open, then send | "This form was updated…"; the new questions load |

### E. Situation Room

| # | Step | Expected |
|---|---|---|
| E1 | Situation Room › Feedback | "Service experience ratings by question": one row per question, five colours from orange (dissatisfied) to blue (satisfied) |
| E2 | Same page | "Answers to choice questions": answer, number chosen, share. No written answers, names or phones. |
| E3 | Sign in as a sub-city user | Only that sub-city's and its woredas' answers; never a sibling's |

## 3. Record of the validation run (17 October 2026)

Local environment: PostgreSQL, production build, `php artisan serve`,
Playwright (Chromium). Institution: Trade Bureau (no published service
catalog, one office, no routing rules). Questionnaire version 1 was published
from the template through the admin screen.

| Flow | Viewport / language | Result | Stored (verified in the database) |
|---|---|---|---|
| Feedback | 375 px, English | Pass | type feedback, target Trade Bureau, `question_set_id` = v1, rating 4, `service_text`, time band morning; 6 answers exactly as entered (4 ratings, yes, long text); history `pending`; contact encrypted |
| Complaint + 1 PNG | 320 px, Amharic | Pass, after the language fix below | `PSC-2026-000001`, status `unrouted`, history `pending → unrouted`, 2 answers, evidence on the private disk under a random name |
| Tip-off, anonymous | 430 px, Amharic | Pass | `PTO-2026-000001`, incident date picked as ጥቅምት 3, 2019 and stored as `2026-10-13` |
| Double tap on Send | 375 px | Pass | one POST, one row |
| CSRF-less POST | — | Pass | redirect, nothing stored |
| `/storage/service-feedback/…` | — | Pass | 403 |
| axe-core (WCAG 2.0/2.1/2.2 A and AA) | 375 px, three steps including an error state | Pass | 0 violations |
| Horizontal overflow | 320, 375, 430 px | Pass | none |
| Console and network | all flows | Pass | no errors or warnings, no 4xx/5xx |

Defects found during the run, all fixed (see the production-readiness
document): questions and categories were in English on a first Amharic visit;
the Complaint receipt named a handling team even when the complaint was
unrouted; the logo URL carried the organization id; the Situation Room scale
used unrelated colours for 1 and 5.

Not covered by this run: real devices, screen readers (axe and keyboard only),
and a published service catalog and multiple offices. Automated tests cover
the last two.
