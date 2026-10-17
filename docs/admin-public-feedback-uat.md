# Admin: Public Feedback UAT

Acceptance scripts for the admin side of Public Feedback Management, and the
record of the validation run on 17 October 2026.

## 1. Accounts to prepare

Use the published hierarchy Bureau → Sub-city A (Woreda A1, Woreda A2) →
Sub-city B. Create one account per row, each with an organization scope of
type **subtree**.

| Account | Scope | Grants |
|---|---|---|
| W-A1 | Woreda A1 | view, complaints.view, view_organization_target, view_employee_target |
| SC-A | Sub-city A | the same + review, complaints.review, resolve, close, assign |
| BUR | Bureau | the same + view_submitter_identity, view_sensitive_attachments, export |
| W-A2 | Woreda A2 | as W-A1 |
| GEN | Bureau | view and view_organization_target only (no Complaint grant) |

Through Woreda A1's feedback QR, submit one Feedback, one Tip-off and one
Complaint with a photo. Leave the routing rules empty so the Tip-off and the
Complaint are unrouted.

## 2. Scripts

### A. Scope

| # | As | Step | Expected |
|---|---|---|---|
| A1 | W-A1, SC-A, BUR | Public Feedback → All Submissions | All three submissions about Woreda A1 |
| A2 | W-A2 | the same | None. Pasting the Complaint's detail URL gives "403 Forbidden" |
| A3 | SC-A | Change `organization_id` in the URL to Sub-city B | No rows; cards at 0 |
| A4 | GEN | All Submissions; Submission reports → Export CSV | Feedback and Tip-off only. The export has no Complaint row |

### B. List

| # | Step | Expected |
|---|---|---|
| B1 | Read the columns | Reference, type, Institution badge with name and code, organization, category, status, submitted date and time, handler |
| B2 | Filter Type = Complaint, then Target = Institution, Status, Identity, a date range | Each narrows the list; the cards keep the whole split |
| B3 | Search the Complaint's reference in lower case | That Complaint |
| B4 | Search a word of the complaint text | Nothing: the narrative is not searchable |
| B5 | Sort by oldest, status, institution, category | Order changes; pages stay at 25 |
| B6 | Switch to አማ | Amharic labels, Ethiopian dates, Amharic institution names |
| B7 | Phone width (390 px) | Cards instead of the table; no sideways scroll |

### C. Feedback

| # | Step | Expected |
|---|---|---|
| C1 | Open the Feedback | "Feedback for <institution>", the logo and code, the service (flagged when given in the visitor's words), the questionnaire answers as "4/5 · Satisfied", Yes/No, text |
| C2 | Review: Reviewed, with a note | History: "Reviewed · <you>" with the note. The offered moves become Resolved and Close |
| C3 | Resolve, then Close (with the close grant) | History grows. No further move is offered |
| C4 | On another Feedback: Hide, then Restore | It returns to the status it had, and both steps are in the history |

### D. Complaint and Tip-off

| # | Step | Expected |
|---|---|---|
| D1 | Public Feedback → Unrouted | The Tip-off and the Complaint |
| D2 | Open the Complaint | Warning "not matched by any routing rule"; the institution; the evidence list (BUR only); the identity (BUR only, otherwise "Hidden") |
| D3 | Assign a handler, with a reason | Status Assigned; history "Unrouted → Assigned"; it leaves the Unrouted queue; the institution is unchanged |
| D4 | Under review → Resolved → Closed | Each step in the history. Only the next valid step is ever offered |
| D5 | As W-A2, paste the evidence download URL | 403 |

### E. Exports

| # | Step | Expected |
|---|---|---|
| E1 | In English: Submission reports → CSV, then Excel | English headers and values, Gregorian dates |
| E2 | In Amharic: the same | Amharic headers (ተቋም, ሁኔታ…), Amharic values (ቅሬታ, ያልተላለፈ), Ethiopian dates (ጥቅምት … 2019) |
| E3 | As SC-A without the identity grant | No name or phone in the file |

## 3. Record of the validation run (17 October 2026)

Local PostgreSQL, production build, Chromium at 1366 and 390 px, signed in
as the demo super administrator. The data was the three Trade Bureau
submissions from the public-form run, plus one older employee-targeted
Feedback.

| Check | Result |
|---|---|
| All Submissions (EN): 4 rows (3 institution, 1 employee), cards 4/2/1/1, open 4, unrouted 2 | Pass |
| Unrouted (AM): the Tip-off and the Complaint, Ethiopian dates, Amharic labels and names | Pass |
| Feedback detail (AM): institution with logo and code; service flagged as the visitor's words; version-1 answers; history | Pass |
| Review → Reviewed with an Amharic note: history row; next moves Resolved and Close | Pass |
| Complaint (AM): unrouted warning; assign dialog lists the institution's users; assigned with a note; history Unrouted → Assigned; next move Under review only | Pass |
| Stored `<script>` and `<b>` text shown as literal text | Pass |
| Phone width 390 px: no sideways scroll | Pass |
| Console and network: no errors or warnings, no 4xx/5xx | Pass |
| axe (WCAG 2.0/2.1 A, AA) | Fixed: reference-link contrast (new page) and an invalid `<dl>` (Complaint detail). Remaining, not from this phase: the amber "Pending" badge text at 4.44:1 (needs 4.5:1), a shared theme token |
| English "required" and "4 out of 5" in Amharic screens | Fixed (shared form label; rating text) |

Automated: `tests/Feature/PublicFeedback/AdminPublicFeedbackManagementTest.php`,
24 tests covering scope for each type and target, the Complaint grant,
forged filters, the list's privacy, filters and reconciliation, search, the
unrouted queue, sibling denial on every action and file, the Feedback,
Complaint and Tip-off workflows, hide/restore, identity in payloads, the
Amharic export, and a notification failure.
