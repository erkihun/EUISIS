# Agent B — ID card lifecycle & public verification
Accounts qa.sysadmin2 / qa.cardofficer / qa.cardapprover. Script 20-idcards-lifecycle.js (63 steps: 60 PASS / 3 FAIL, all real), results/20-idcards-lifecycle.json, results/20-idcards-state.json, shots/idc-*.png (43).

Verdict: lifecycle engine (ordering rules, frozen print, batch, issue/activate, revoke, pre-consent privacy, 301 legacy redirect, graceful PNG 503) works and is audited, but NOT go-live ready for its intended roles: ID Card Officer cannot submit a request (403), no segregation of duties, holder-consent OTP cannot be delivered.

Journeys: J1 officer creates request FAIL (403; admin fallback OK, audit card_requested); J2 segregation FAIL (requester self-approves; officer approves); J3 print batch→confirm→issue→activate + ordering rules PASS (issue/activate before print 403, mark printed before frozen print refused, audit chain complete); J4 public verification PASS / OTP blocked (anonymous page shows only status + masked number, props carry no PII, random uuid → "Not valid", legacy /verify/card → 301, OTP wrong code 422 + audit); J5 lost/revoke → checker shows status, replacement links prior card (no suspend route); J6 preview SVG 200, PNG → 503 text "requires Imagick" (no 500), no PDF route; J7 authorization: officer /users 403, approver print-batch pages 403; J8 0 page errors, 0 5xx, slow pages under load (id-cards show am 8.4 s, card-requests am 7.4 s).

Defects:
- D1 Critical: ID Card Officer cannot submit a card request — POST /card-requests 403. CardRequestController.php:108,115 authorize('view', $employee) → EmployeePolicy::view requires employees.view, which the role lacks. Go-live F3 step 1 impossible for the role.
- D2 High: no segregation of duties — requester approves own request (approved_by = requested_by). CardRequestPolicy.php:49-53 / ApproveCardRequestAction never compare actor with requested_by. Contradicts go-live F3 "a different approver approves".
- D3 High: ID Card Officer can approve/reject/verify (cards.manage accepted as sufficient in CardRequestPolicy::approve/reject/verify; IdCardPolicy grants issue/activate/revoke via cards.manage); Approve button shown to officer.
- D4 Critical (HR): Register employee → HTTP 500 "Error 500 … reference ERR-FBLWJZTJE9LX", deterministic (same root cause as lead finding: unique job lock in transaction on PostgreSQL).
- D5 Medium (HR): "Save Employee" triggers the unsaved-changes confirm dialog on its own submit; Cancel silently drops the save (Employees/Create.tsx:191-208 router.on('before') guard fires before form.processing).
- D6 Medium (privacy): public checker distinguishes existing cards from unknown before consent (unknown → "Not valid"; existing → Revoked/Reported lost/Active + last 4 digits) — contradicts PublicIdCheckerService.php:24-28,71-73 "must be indistinguishable" → enumeration oracle (PublicIdCheckerController::show :69-78).
- D7 Medium: lost/damaged request approved via Create-form path leaves two is_current cards (ApproveCardRequestAction.php:75-82 retires prior card only for Correction).
- D8 Low: card_replacements.new_card_id never updated (ReplaceCardAction.php:85 TODO).
- D9 Low: replaced card page links first replacement not newest (IdCard::replacementCard hasOne previous_card_id, IdCard.php:103-106).
- D10 Low: raw i18n keys `idCards.status_submitted/verified/approved` in lifecycle timeline (CardLifecycleTimeline.tsx:72,79; bundles define requestStatus_*).
- D11 Low: no suspend route although CardStatus::Suspended exists; POST /id-cards/{card}/incident status=suspended → 302 → GET 405 (routes/web.php:955-959); lost-card modal says "This will suspend the card."
- D12 Low: audit noise/gaps — card_printed written twice per card; card.exported_png_server audited even when export returns 503 (IdCardExportController.php:83-88); no card_requested row for ReplaceCardAction requests.
- D13 Low: replace rules disagree across layers (ReplaceCardRequest/IdCardPolicy vs ReplaceCardAction status lists).
- D14 Low (HR): employee update re-derives full_name from parts, overwriting stored value (EmployeeController.php:764) — EMP-0003 "Printed Card Employee" → "Printed Card" (restored by lead).
- D15 Medium (config): holder OTP cannot be delivered — system_settings mail_mailer=smtp with empty host → connection refused 127.0.0.1:587; SMS gateway unconfigured; audit "OTP sent via: none" while endpoint reports sent:true; email_notifications_enabled=false yet OTP mail attempted. Go-live F4 could not be completed.

Observations: card_requests has no human-readable request number (uuid only); Amharic leaks on card request list (org via hard-coded name_en CardRequests/Index.tsx:171, "Ctrl K", role label, aria-labels), "Landscape/Portrait" toggles; post-consent field set from code: full_name, employee_number, organization, unit, position, card_number, status, issued/expires/verified_at. Residual QA data: EMP-0003 has 5 cards, 4 batches, 4 requests.
Untested: OTP success reveal, active-card replace path, verify/reject/cancel UI, NFC provisioning, PDF export (no route).
