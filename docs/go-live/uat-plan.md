# User acceptance test (UAT) plan

Status on 24 October 2026: **plan only**. No UAT scenario has been executed. The result column is left empty on purpose.

Audience: the business owner, the UAT testers and the operations team. The criterion IDs (A1–A18) refer to [core-acceptance-matrix.md](core-acceptance-matrix.md).

## 1. Gate

- UAT is **mandatory** and is a **blocking go-live gate**. The business owner's sign-off (section 7) is required before go-live.
- The testers are the real business roles listed in section 2, and they run real business scenarios on test data. Developers support the testers but do not execute scenarios and do not sign.
- Go-live is not allowed while any P0 or P1 business or security defect is unresolved.
- Evidence is recorded as references, and `php artisan production:readiness --strict` checks them: `GOLIVE_UAT_SIGNOFF` (the signed sign-off reference) and `GOLIVE_UAT_OPEN_P0_P1` (the count of open P0/P1 defects, which must be 0). See [go-live-gates.md](go-live-gates.md).
- The business owner signs "Business acceptance (UAT)" in the matrix.

## 2. Testers and test accounts

The operations team provides the test accounts on the UAT environment, one per tester. No account is shared, and privileged accounts are enrolled in MFA. The test data contains organization A (with child organization A1), organization B and synthetic employees. It contains no real personal data.

| Code | Business tester | System role(s) | Organization scope |
| --- | --- | --- | --- |
| CA | City administrator | City Admin | global (all organizations) |
| IA | Institution (organizational) admin | Organizational Admin | `subtree` on A |
| HR | HR officer | HR Officer | `self` on A |
| HR-N | HR officer without a scope record | HR Officer, linked to an employee of B | no record (expected reach: B only) |
| HR-0 | HR officer without a scope record or a linked employee | HR Officer | no record (expected reach: nothing) |
| IDO | ID card officer | ID Card Officer | `self` on A |
| IDA | ID card approver | ID Card Approver | `self` on A |
| NFC | NFC provisioning officer (only if NFC is used) | NFC Provisioning Officer | `self` on A |
| SP | Service provider (cafeteria, transport) | provider portal user; Cafeteria Operator; Transport Operator | own provider |
| AU | Auditor | Auditor | `self` on A |
| EMP | Employee (self-service) | employee portal account | own records |
| GC | Grievance committee | Grievance Officer, Grievance Committee Member, Grievance Committee Chairperson | A |
| DC | Discipline committee | Discipline Case Officer, Disciplinary Committee Member, Disciplinary Decision Authority | A |
| PC | Performance and appeal committee | Performance Officer, Performance Manager, Performance Appeal Committee | A |
| INT | Partner engineer with an API manager | API Manager; a test application (`scoped` on A, bound to a test provider) | A |
| OPS | Operations | operations account and the server console | — |
| PUB | Public visitor | none (a mobile phone) | — |
| LANG | Amharic language reviewer | a read-only account | A |

## 3. Entry criteria

| # | Criterion |
| --- | --- |
| E1 | The release candidate is deployed on the UAT environment (not production) with production settings: MFA enforced and `ORGANIZATION_SCOPE_REQUIRE_EXPLICIT=true`. |
| E2 | The automated evidence for that release candidate is recorded in the matrix. |
| E3 | `php artisan core:smoke` and `php artisan production:readiness` have been run on the UAT environment. Every failure except the open go-live gates is fixed or listed. |
| E4 | The test data is loaded, and `php artisan access:scope-gaps` has been reviewed so that every tester account has its intended scope. |
| E5 | Accounts are handed out, and testers are briefed on this plan and the defect form. |
| E6 | The card printer, a QR-scanning phone, a transport device and (if used) an NFC terminal are available. The partner test application and a webhook receiver are configured. |
| E7 | The defect register is open, and a triage owner is named. |

## 4. Scenarios

Results are recorded as Pass; Fail, with the defect ID; Blocked; or N/A, with a written reason. The steps are summaries, so testers record what they actually did.

### 4.1 Golden Path

| ID | Scenario | Steps summary | Expected result | Criterion | Tester | Result |
| --- | --- | --- | --- | --- | --- | --- |
| GP-01 | Create an organization | `/organizations`: a new organization under A, with Amharic and English names | It appears in the hierarchy and on the Core dashboard, and an audit row names the actor | A1 | CA | |
| GP-02 | Create a unit | `/organization-units`: a new unit in A1 | The unit appears only in A1's tree | A1 | IA | |
| GP-03 | Create a position | `/positions`: a position in the unit; approve the establishment | The position is active and vacant | A1 | HR, IA | |
| GP-04 | Register an employee | `/employees/create`, with dates entered in the Ethiopian calendar | An employee number is generated, and the dates show in both calendars | A1, A10 | HR | |
| GP-05 | Import employees | `/employees/import`: download the template, upload good and bad rows, confirm | Bad rows are listed with reasons, nothing is saved before the confirm step, and the good rows are imported | A1 | HR | |
| GP-06 | Assign to a position | Assign the employee to the vacant position | An active assignment shows in `/core/assignments`, and the position is occupied | A1, A3 | HR | |
| GP-07 | Request an ID card | `/card-requests/create` for the employee, then verify the request | The request waits for approval | A1 | IDO | |
| GP-08 | Approve and reject | Approve one request; reject another with a reason | The approved request moves on; the rejected one closes and shows its reason | A1 | IDA | |
| GP-09 | Print batch | `/print-batches/create`, print preview, mark printed | Front and back are correct: photo, Amharic and English names, organization, position, QR | A1, A9 | IDO | |
| GP-10 | Issue and activate | On the card page, issue and then activate the card | The card is active and appears in the employee portal | A1 | IDO, EMP | |
| GP-11 | Verify the QR | Scan the printed QR with a phone; the holder relays the one-time code | Before the code: only "active card", with no personal details. After the code: the identity is shown | A5 | PUB, EMP | |
| GP-12 | Lost card and replacement | Report the card lost, replace it, then issue and activate the new card | The old QR shows invalid and the new card is valid. The history links both cards | A1, A5 | IDO | |
| GP-13 | Suspend and revoke | Record a suspension incident on one card; revoke another | Both show invalid at the checker, at the cafeteria scan and through partner verification | A5 | IDO, SP | |
| GP-14 | Employment status change | Edit the employee and change the status (for example, to retired) | The assignment ends, the position is vacated, services refuse the card and the history is kept. A same-day undo restores the assignment | A3 | HR | |
| GP-15 | Transfer | Transfer the employee from A to B through the transfer flow | Core moves the assignment: A's HR loses access and B gains it. The history shows both organizations | A3, A4 | HR, IA | |
| GP-16 | Transfer by vacancy announcement | Publish an announcement; the employee applies in `/my-portal`; receiving approval, then final approval | The assignment moves only after the final approval | A3 | IA, EMP, HR | |

### 4.2 Service integrations

In the matrix, A8 shows UAT as "n/a". SV-05 to SV-07 add business evidence for it.

| ID | Scenario | Steps summary | Expected result | Criterion | Tester | Result |
| --- | --- | --- | --- | --- | --- | --- |
| SV-01 | Cafeteria scan | `/provider/portal/cafeteria/scan` (or `/cafeteria/scan`) with an active card, then scan again in the same meal period | Served once; the repeat is refused under the entitlement rules; the transaction is in the provider ledger | A17 | SP | |
| SV-02 | Cafeteria refuses invalid cards | Scan suspended, revoked, replaced and former-employee cards | Refused with a clear reason, and no transaction is recorded | A5 | SP | |
| SV-03 | Transport boarding | `/transport/scan` or the transport device: a valid card, then an invalid one; take the device offline, then sync | The valid card boards and the invalid one is refused. Offline records sync once | A5 | SP | |
| SV-04 | NFC terminal (if used) | Provision NFC on a card, tap it, revoke the card, tap again | Accepted, then refused | A5 | NFC, SP | |
| SV-05 | Grievance about an employee | The employee files in `/my-portal/grievances`; the officer registers it; the committee reviews and decides | Employee, organization and position come correctly from Core. Only the parties and scoped officers see the case | A4, A8 | EMP, GC | |
| SV-06 | Discipline case | The case officer opens a case; the employee responds and uploads evidence; the committee reviews; the decision authority decides | Evidence is visible only to the parties. The decision is recorded and shown to the employee | A4, A8 | DC, EMP | |
| SV-07 | Performance and appeal | The officer sets the agreement; the employee acknowledges it; the manager evaluates; the employee appeals; the appeal committee decides | Items are locked once the agreement is active. The appeal outcome is recorded | A8 | PC, EMP | |
| SV-08 | Public feedback by organization QR | Generate the QR at `/organizations/{organization}/feedback-qr`; a visitor submits feedback; rotate the QR | The submission appears in `/feedback-admin` for that organization only. The old QR stops working after the rotation | A4 | IA, PUB | |
| SV-09 | Partner API read | The test application reads A's organizations and employees, then requests an employee of B | A's data is returned. The request for B's employee gets 404 | A4, A7 | INT | |
| SV-10 | Partner card verification | Verify active, revoked, replaced and expired cards; make a person call to `POST /api/v1/cards/verify` without `provider_code` | `valid` is true only for the active card. The call without `provider_code` gets 422 `provider_required` | A5, A17 | INT | |
| SV-11 | Webhooks | Subscribe the receiver; revoke a card; stop the receiver; replay from the Integration Center | A signed `id_card.revoked` arrives. While the receiver is down, delivery retries and then goes dead. The replay delivers | A7 | INT, OPS | |
| SV-12 | Change feed | Read `/api/v1/changes` from a cursor taken before GP-10 | The events from GP-10 to GP-15 appear in order, with no gaps | A7 | INT | |

### 4.3 Core workspace

| ID | Scenario | Steps summary | Expected result | Criterion | Tester | Result |
| --- | --- | --- | --- | --- | --- | --- |
| CW-01 | Core dashboard | `/core/login`, then `/core` | The figures match the test data, allowing for the cache time | A6 | CA | |
| CW-02 | Assignments | `/core/assignments`, filtered by organization | The assignments from GP-06, GP-14 and GP-15 appear with their history | A6, A3 | HR, CA | |
| CW-03 | Integration center | `/core/integration` | The test application, its subscription, the delivery states and the access-review flags are visible | A6, A7 | INT | |
| CW-04 | Audit center | `/core/audit` | Every action from 4.1 is listed with actor, organization and request ID. Nothing can be edited | A6, A16 | AU | |
| CW-05 | Settings | `/core/settings` | Authorized roles can open it; HR is refused | A6 | CA, HR | |

### 4.4 Organization scope by role

| ID | Scenario | Steps summary | Expected result | Criterion | Tester | Result |
| --- | --- | --- | --- | --- | --- | --- |
| SC-01 | City admin | Open the employee list, the card list and the Core dashboard | All organizations | A4 | CA | |
| SC-02 | Institution admin (`subtree` on A) | The same pages | A and A1 only; B is absent | A4 | IA | |
| SC-03 | HR officer with a `self` scope | The same pages; then paste the URL of a B employee | A only. The pasted B URL is refused | A4 | HR | |
| SC-04 | HR officer without a scope record (linked to B) | The same pages | B only (the linked employee's own organization) | A4 | HR-N | |
| SC-05 | HR officer without a scope record or a linked employee | The same pages | No organization data at all | A4 | HR-0 | |
| SC-06 | A scoped admin tries to widen access | IA tries to give HR a `citywide` scope or the Super Admin role | Refused | A4 | IA | |
| SC-07 | Employee self-service | `/my-portal` documents, cases and entitlements; change a record ID in the URL | Own records only; other IDs are refused | A4 | EMP | |
| SC-08 | Auditor | `/core/audit` and `/audit-logs` | Only A's audit rows; nothing can be edited | A4, A16 | AU | |
| SC-09 | Service provider | Provider ledger, transactions and exports | Own provider only | A4, A17 | SP | |

### 4.5 Language and dates

| ID | Scenario | Steps summary | Expected result | Criterion | Tester | Result |
| --- | --- | --- | --- | --- | --- | --- |
| LD-01 | Amharic and English | Switch the language on the admin pages, Core, the employee portal, the provider portal, the checker and the feedback pages | No untranslated keys and no mixed-language screens. Terms follow the [Amharic terminology guide](../amharic-terminology-guide.md) | A9 | LANG | |
| LD-02 | Amharic names | Enter, search for and print Amharic names | They are saved, found and printed correctly on the card | A9 | HR, IDO | |
| LD-03 | Ethiopian and Gregorian dates | Enter dates in both calendars, including Pagume and the year end; check the card expiry and the reports | Each date shows consistently, and the conversion is correct | A10 | HR, LANG | |
| LD-04 | Amharic documents | A grievance letter and the exports | Amharic renders correctly | A9 | GC | |

### 4.6 Errors and edge cases

| ID | Scenario | Steps summary | Expected result | Criterion | Tester | Result |
| --- | --- | --- | --- | --- | --- | --- |
| ER-01 | Out-of-scope access is refused | HR opens a B employee, card and case by URL | A clear refusal or "not found", with no data leaked | A4 | HR | |
| ER-02 | Write freeze message | OPS sets `CORE_MASTER_DATA_WRITES=frozen`; HR edits an employee and IDO issues a card; then OPS unfreezes | A clear freeze message, nothing is saved, and reading still works. After the unfreeze, the edits work | A11 | OPS, HR, IDO | |
| ER-03 | Duplicate and invalid actions | A second active card request for the same employee; replacing an active card; an import with duplicate employees | Each is refused with a clear message | A1 | IDO, HR | |
| ER-04 | Expired card | Present a card past its expiry at the checker and at the cafeteria | Invalid at both | A5 | PUB, SP | |
| ER-05 | Smoke test on the target | OPS runs `php artisan core:smoke` and the manual checks in step 11 of the [go-live runbook](go-live-runbook.md) | Every check passes | A12 | OPS | |

## 5. Defect severity and priority

| Priority | Matrix severity | Definition | Example |
| --- | --- | --- | --- |
| P0 | 1 | Wrong access across organizations, wrong card validity, data loss, or an exploitable Critical/High security finding | A's HR officer sees B's employees; a revoked card is accepted |
| P1 | 2 | Blocks a business task, or a High security finding whose workaround is still unsafe | Cards cannot be activated |
| P2 | 3 | A workaround exists | A filter fails, but search finds the record |
| P3 | 4 | Cosmetic | Spacing; wording that does not change the meaning |

Every defect goes into the defect register in [core-acceptance-matrix.md](core-acceptance-matrix.md#defect-register) as `D-n`, with its severity, priority, scenario ID, a one-line description and its state. Attach the steps, the expected and actual results, and screenshots that show test data only. The triage owner reviews new defects daily. The business owner settles any dispute over priority, and the higher priority applies until then.

## 6. Exit criteria

- Every scenario has been executed: it passed, or it is N/A with a written reason that the business owner accepted (for example, NFC not used at go-live).
- No P0 or P1 (Severity 1 or 2) defect, business or security, is open. Go-live is not allowed while any P0 or P1 business or security defect is unresolved.
- Every fixed defect has been retested on the build that goes live.
- Open P2 and P3 defects are listed with an owner and a date, and the business owner has accepted them in writing.
- The matrix's UAT column is updated for each criterion covered in section 4.
- The sign-off below is complete, `GOLIVE_UAT_SIGNOFF` holds its reference, and `GOLIVE_UAT_OPEN_P0_P1` is 0.

## 7. Sign-off

The business owner's signature is mandatory. The other signers confirm their own areas.

| Role | Name | Area confirmed | Build / version | Open P0/P1 | Date | Signature |
| --- | --- | --- | --- | --- | --- | --- |
| Business owner (mandatory) | | | | | | |
| HR | | | | | | |
| Institution (organizational) admin | | | | | | |
| ID card office | | | | | | |
| Service provider | | | | | | |
| Auditor | | | | | | |
| Grievance committee | | | | | | |
| Discipline committee | | | | | | |
| Performance / appeal committee | | | | | | |
| Employee representative | | | | | | |
| Amharic language reviewer | | | | | | |
