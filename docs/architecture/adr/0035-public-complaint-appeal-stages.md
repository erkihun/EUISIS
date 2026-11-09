# ADR 0035: Appeal stages for public complaints

- Status: accepted
- Date: 2026-11-08
- Owner request (2026-11-08, Amharic, summarised): some institutions have two
  public complaint committees. A complaint goes first to the complaint
  committee, which answers the complainant. A complainant who is not
  satisfied appeals to the second committee; the complaint then becomes an
  appeal (አቤቱታ) and that committee is the appeal committee (አቤቱታ ሰሚ ኮሚቴ).
  It may uphold or overturn the first answer. A complainant still not
  satisfied takes it to the head of the institution. Owner follow-up: the
  complainant's name is mandatory on a public complaint (it already was:
  `ComplaintSubmissionType` requires name and phone, no anonymity).

## Decision

1. **Three stages**, one row each in `public_complaint_stages`: 1 the
   complaint committee, 2 the appeal committee, 3 the head of the
   institution. A row keeps the appeal reason that opened it, the decision
   (`answered` at stage 1, `upheld` or `overturned` later), the response
   given to the complainant (encrypted), who decided and when, the appeal
   deadline, and what the complainant did (`accepted`, `appealed`, `lapsed`).
   The complaint keeps its reference and its one status workflow; an appeal
   moves it `resolved → assigned` (or `unrouted`), set by the system only.
2. **Who hears each stage** is a complaint handling unit (ADR 0034) with a
   `stage`: `first_instance`, `appeal` or `head_office`. An institution has at
   most one active appeal committee and one head's office. No reliable
   "institution head" exists in the organization data, so the head is
   registered as the head member of the head's-office unit, with signature,
   like any committee head.
   Owner follow-up (same day): these are **institution committees, not work
   units**. A complaint committee, appeal committee or head's office has its
   own name and no `organization_unit_id`; its members are any active
   employees of the institution, from any unit. Cases go to it with the
   handler type `committee` (id = the committee row) and reach its serving
   members; its head hands a case to one member like a unit head. Older
   unit-based complaint units keep working.
3. **Routing**: a complaint that matches no routing rule goes straight to the
   institution's complaint committee. Appeals are never moved up the
   first-instance escalation ladder (ADR 0033); they go to the stage's unit.
   With no appeal committee, an appeal goes straight to the head; with no
   head's office it waits unrouted for a coordinator.
4. **Deciding**: resolving a complaint is its stage's decision and needs the
   response the complainant will read; stages 2 and 3 also need uphold or
   overturn. Whoever decided an earlier stage never works or decides a later
   one (checked in the policy and again in the service, because Super Admin
   passes policies through `Gate::before`). The head's decision is final.
5. **The complainant** sees each stage's response, never staff names, on the
   public tracking page and answers the latest: accept (the complaint closes)
   or appeal with a reason of at least 10 characters. Answering needs a
   lookup by reference and phone in the same browser session within 30
   minutes. SMS is not wired on the platform, so nothing is texted.
6. **Periods are the owner's**: `complaint_appeal_days` (how long the
   complainant has to answer) and `complaint_appeal_response_days` (how long
   an appeal stage has to decide) are public feedback settings, empty by
   default. No legal period is assumed. With an appeal period, staff cannot
   close a decided complaint while it runs, and `public-complaints:close-lapsed-appeals`
   (hourly) closes complaints whose period passed with no answer. Without
   one, the appeal stays open until staff close the complaint.
7. The complaint-admin/units page shows each unit's stage and lists the
   institutions missing a stage.

## Consequences

- Tip-offs and service feedback are unchanged.
- Existing complaints have no stage rows; their first decision creates stage 1.
- Pending owner action: set the two periods and designate the appeal
  committee and head's office per institution.
