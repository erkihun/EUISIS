# Executive Situation Room — Community Voice Analytics

Covers Public Feedback, Tip-offs (ጥቆማ, stored as type `suggestion`) and Public
Complaints, plus their use in Service Delivery, Overview, Trends and Critical
Attention. Builds on `PublicFeedbackMetrics` semantics and
`docs/situation-room-community-voice.md`.

## Principles

1. **A complaint volume is not guilt.** Panels say "received", organization
   rows are in name order, and no chart sorts organizations or employees by
   how much was said about them.
2. **Public Complaint ≠ Employee Grievance ≠ Discipline ≠ Appeal.** Community
   voice never counts grievances, disciplinary cases or appeals, and the case
   summary never counts public submissions.
3. **No identity, narrative or evidence.** Payloads carry counts, category and
   service names only. Verified by `SituationRoomScopeTest` ("keeps submitter
   identity and narrative out of every public-voice payload").
4. **No sentiment scoring.** Text is never analysed; only structured fields
   (type, Tip-off type, status, rating, category, service, channel, target) are
   counted.

## Base query

`PublicVoiceAnalytics::base()` — `employee_service_feedback` of one type,
`status <> 'hidden'`, confined to the scope on `organization_id` (the
organization snapshot at submission time), then narrowed by target type,
status, category, service, unit and (Tip-offs) Tip-off type. Period filters
use `submitted_at`; supported by `esf_org_type_submitted_idx`.

## Tip-off types

Corruption (የሙስና ጥቆማ) and Improper practice (የብልሹ አሠራር ጥቆማ). Tip-offs stored
before the type existed are shown as *Unclassified (older)*, never guessed;
the three buckets always add up to the total.

## Service linkage

Only the employee QR offers a service choice, so institution-QR submissions
cannot name a service. When nothing in a slice names one the panel reports
**SERVICE_LINKAGE_REQUIRED**; Service Delivery shows the share that does.

## Employee breakdown

`*_employees` tables list employees with the number of submissions about them
— only with `situation_room.view_employee_targeted` and
`service_feedback.view_employee_target`, only inside one focused
organization, in name order, 25 per page, the viewer's own record excluded,
each view audited, never on the wall or in an export.
