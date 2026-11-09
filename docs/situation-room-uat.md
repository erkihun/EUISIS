# Executive Situation Room — User Acceptance Test Script

Run in staging with production-like data. Record pass/fail and screenshots.
Each step lists the expected result. EN and AM: repeat steps marked † with the
language switched to Amharic.

## A. Access and navigation

1. Sign in as a Bureau executive holding `situation_room.*`. † The sidebar shows **Situation Room** first with *Executive Situation Room*, *Wall Mode*, *Executive Reports*.
2. Sign in as a user without `situation_room.view`. No Situation Room group; `/situation-room` returns 403.
3. Give a user only `situation_room.view` + `public_voice.view`. The launcher lists only Overview, Structure, Critical Attention, Trends, Data Quality and the four community-voice categories; `/situation-room/workforce` returns 403.

## B. Scope and roll-up (use real Bureau → Subcity → Woreda data)

4. Bureau executive, Workforce: rows are *Bureau (own office)* plus one per subcity/institution; each subcity row equals the sum of its woredas plus its own office (check against HR reports).
5. Subcity A administrator: the launcher drops one level automatically (rows: Subcity A own office, its woredas). No other subcity appears anywhere, including exports.
6. As the Subcity A administrator, edit the URL to `?organization_id=<a woreda of Subcity B>`. Expect 403.
7. Woreda (self-scoped) user: only that woreda's figures.
8. Drill down with *Look inside* and the scope trail; the category and period are kept.

## C. Figures

9. † Workforce headcount equals the count of current active assignments in HR for the same scope; capacity/vacancy match the Positions establishment screen.
10. Complaints received this month equal the Public Submissions report for the same organization and month; Tip-off types add up to the total.
11. Daily Operations: awaiting review equals the reviewers' queue for the scope.
12. Field Work under way equals the field-work oversight list (status *In the field*).
13. Change the period (today, week, month, quarter, year, custom). The trend granularity switches day → week → month; "previous period" values match manual counts.

## D. Privacy

14. No page, wall or export shows a submitter's name, phone, address, message or evidence.
15. Employee table (Complaints › focus one woreda) appears only with `view_employee_targeted` and `service_feedback.view_employee_target`; it is in name order, excludes the viewer, and an audit entry *employee breakdown viewed* is recorded.
16. Grievance, Discipline & Appeal shows counts only; small counts show "fewer than 5".

## E. Critical Attention and settings

17. With no thresholds set, every rule shows *Not configured* and counts nothing.
18. Set *Complaint open (days)* = 14 in System Settings › Situation Room. The rule shows counts per organization, or *Nothing past the limit*.

## F. Action Register

19. Focus a category on one woreda; *Record an action* on a panel opens the Action Register with the institution and the panel (source *panel*) pre-filled.
20. Save with an owner and due date; the action appears in the register and on the Executive Overview's open actions. Change its status; the history lists each change with its author. Audit shows created / assigned / status changed.
21. A user of another subcity does not see the action and cannot change it (403).
21a. Community Voice by Institution (`/situation-room/community-voice`) opens from the launcher and the header; its targeted-employee pages and queued export work as documented in executive-situation-room.md.

## G. Reports

22. † Export Complaints as CSV, Excel and PDF in English (Gregorian dates) and Amharic (Ethiopian dates). Figures equal the screen; no names; the scope and period are printed on the PDF; an *exported* audit entry exists.

## H. Wall Mode

23. Open Wall Mode on a 1920×1080 display. Screens rotate; Space pauses; ← → step; figures refresh on the configured interval; no sidebar; no names; footer shows the computation time.

## I. Resilience and layout

24. Check 390, 768, 1024, 1440 and 1920 px widths: no horizontal page scroll, cards stack, charts readable.
25. Keyboard only: every filter, tab, card and *Show the numbers* toggle is reachable; charts have a text alternative table.
26. Simulate a failing panel (staging only, e.g. revoke DB read on one table): only that card shows *Could not load*; the rest of the page renders.
