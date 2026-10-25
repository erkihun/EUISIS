# Core Golden Path: business UAT package

**Status: NOT STARTED.** Business UAT has not happened, and nobody has signed
it off. This package prepares it. The results and signatures can only come
from the authorized business testers and owners. The automated UAT
(`CoreGoldenPathUatTest`, 19 refusals) and the staging smoke test
(`deploy/staging/golden-path-smoke.php`) do **not** replace this.

## Environment

- A **UAT environment**, not production and not staging-for-production. Use
  `APP_ENV=uat`; `core:seed-uat` refuses production. Give it its own
  database, its own storage and its own secrets.
- Deploy the release candidate with `deploy.sh`, then run `php artisan core:seed-uat`.
  It loads a synthetic dataset (no real personal data): the city, bureaus
  and sub-cities, units, positions with seats, employees in every state,
  cards in every state, data-quality findings, an API client and an inactive
  webhook. It prints the UAT accounts and one password, **once**. The UAT
  coordinator passes the password to the testers by an agreed channel, never
  by e-mail in clear.
- Testers enrol MFA at first sign-in on their own authenticator.
- The UAT URL, the window and the coordinator are **TBD** (owner matrix).

## Roles

`core:seed-uat` creates accounts for the three system roles. The business
roles below are built on them, or as custom roles (Users › Roles) from the
permission catalog. **The permission sets in this table are a proposal.** The
business owner confirms each one before UAT. Changing a role is itself a UAT
scenario (A-08).

| Business role | Core role | Scope | Proposed permissions (from `CorePermissions::CATALOG`) | Confirmed by |
|---|---|---|---|---|
| City Admin | `core_admin` | city-wide | all | TBD |
| Organization Admin | custom "Organization Admin" | subtree of their organization | organizations.*, organization-units.*, positions.*, position-establishments.*, users.manageUsersWithinScope, reports.view | TBD |
| HR Officer | `core_officer` | their organization (subtree) | employees.create/update/submit, employee_documents.upload, employee_photos.upload, employee_signatures.upload, card-requests.create, assignments.change | TBD |
| Verifier | custom "Verifier" | their organization | employees.verify, employees.return, employee_documents.verify, employee_photos.verify, employee_signatures.verify, card-requests.verify | TBD |
| Approver | custom "Approver" | their organization | employees.approve, card-requests.approve, position-establishments.approve, positions.approve | TBD |
| ID Card Officer | custom "ID Card Officer" | city-wide or their organization | card_print_batches.manage, id-cards.print/issue/activate/replace/suspend, cards.quality_check, cards.reprint, card_templates.view | TBD |
| Auditor | custom "Auditor" | city-wide | audit.view, audit.integrity.view, audit.integrity.verify, security_dashboard.view, reports.view (read-only) | TBD |
| Integration Admin | custom "Integration Admin" | city-wide | integrations.view, integrations.manage, api_management.view | TBD |
| Scoped Viewer | `core_viewer` | one sub-city | view permissions only | TBD |

## Scenarios

Each scenario records a result (Pass / Fail / Blocked), the record ids or
screenshots it produced, the tester and the date. A failure gets a defect id.
Run every scenario in Amharic **and** English unless it says otherwise.

| ID | Scenario | Role(s) | Expected result |
|---|---|---|---|
| O-01 | Register an organization under a bureau | City Admin | created and audited; appears in the hierarchy |
| O-02 | Hierarchy change: draft a version, move an organization, send for review | City Admin | draft and proposed change saved; the drafter cannot approve |
| O-03 | Approve and publish the version | a second City Admin | published; the tree shows the new parent from its effective date |
| O-04 | Add an organizational unit | Organization Admin | unit created inside their organization only |
| O-05 | Create a position, approve it, set seats (establishment) | Organization Admin, Approver | position approved with N seats; over-filling is refused |
| E-01 | Register an employee (draft) | HR Officer | draft created; a missing date of birth or position is refused with a clear message |
| E-02 | Duplicate handling: register someone with the same date of birth and name as an existing employee | HR Officer | the possible duplicate is flagged for review (Duplicates screen); the decision is recorded |
| E-03 | Upload photo, signature and an appointment letter | HR Officer | stored privately; a non-image "photo" or an oversized file is refused |
| E-04 | Submit, then verify (checklist) | HR Officer, Verifier | the submitter cannot verify; the verifier completes the checklist |
| E-05 | Verify the photo, signature and document | Verifier | each verified; a rejected one needs a reason |
| E-06 | Approve the employee | Approver | active; the placement is current; every step is in the audit trail |
| A-01 | Assignment: record a reassignment within the organization | HR Officer | new current placement; the old one is closed; history shows type and reason |
| A-02 | Transfer to another organization (with the transfer letter) | HR Officer of the source, Approver | placement moves; type, reason code and document are recorded; seats are checked |
| C-01 | ID request for the active employee | HR Officer | request created; ineligible employees (no verified photo, not active) are refused with the reason |
| C-02 | ID approval | Approver (not the requester) | approved; the card is pending print |
| C-03 | Print batch: create, print the sheet, mark printed | ID Card Officer | batch recorded; the printer is recorded on the card |
| C-04 | Quality check | a second ID Card Officer | passed or failed with a reason; the printer cannot check their own print |
| C-05 | Issue and activate | ID Card Officer | issued, then active; the digital credential exists |
| C-06 | QR verification on the public check page and in a partner client | Verifier (and Integration Admin for the client) | valid; the partner sees no personal data |
| C-07 | Lost card: report it | HR Officer / ID Card Officer | card suspended or lost; its QR no longer verifies |
| C-08 | Replacement | HR Officer, Approver, ID Card Officer | new card linked to the old; the old QR stays invalid; the new one verifies |
| S-01 | Scope: the Scoped Viewer opens an employee of another sub-city by URL | Scoped Viewer | not found (404); nothing about that person is shown |
| S-02 | Scope: an HR Officer tries to register into an organization outside their scope | HR Officer | refused |
| S-03 | MFA: a privileged account signs in without its second factor | any privileged | asked for the code; no access without it |
| S-04 | Break-glass: request emergency access, approval by another, expiry | City Admin, a second City Admin | granted for the time set; audited; ends on time |
| A-08 | Role change takes effect: remove a permission from a custom role | City Admin | the user loses the action at their next request; their sessions end |
| R-01 | Reports: headcount, card coverage, placements, preview and export | Organization Admin, Auditor | the figures match the screens; the export holds only what the user may see; sensitive columns need reports.export_sensitive |
| R-02 | Data quality: open a finding, acknowledge, resolve or accept the risk | City Admin | lifecycle recorded with reasons |
| AU-01 | Audit: find the employee's history; run integrity verification | Auditor | full trail; verification passes |
| L-01 | Amharic UI: walk E-01…C-05 in Amharic | HR Officer, Verifier | every label, message and date (Ethiopian calendar) is understandable; wording issues go to the native review list |
| L-02 | English UI: the same in English | HR Officer | the same; dates in ISO form |
| N-01 | Notifications: approvals and expiring cards reach the right people, in their language | Approver | in-app notices appear; nothing for people outside the scope |
| SET-01 | Settings › General: change the support e-mail and help link, upload the logo (PNG) | City Admin | saved and audited; the logo shows on every page; an SVG file is refused; a Scoped Viewer cannot open or change settings |
| SET-02 | Settings › Email and Notifications: set the mailer and sender, save the mail password, send the test e-mail | City Admin | the password is never shown back; the test e-mail arrives (if D-04 says e-mail is in V1) or the page says mail is off |
| SET-03 | Settings › Security: change the MFA switches and the session timeout | City Admin | the change applies at the next sign-in; it is audited with old and new values |

## Sign-off record

Use [core-uat-signoff.csv](core-uat-signoff.csv), one row per scenario, filled
in by the tester and countersigned by the business owner. **Do not
pre-fill results.** The record is complete when every scenario has a result.
Every Fail must have a defect id and an agreed resolution. The business
owner's approval reference must be a real signature, a document number or a
ticket the organization recognizes.

| Field | Meaning |
|---|---|
| scenario | the scenario id above |
| business_owner | the accountable person (name and title), from the organization |
| tester | who ran it |
| result | Pass / Fail / Blocked |
| evidence | record ids, screenshot file names |
| defect | defect id when not Pass |
| comment | anything the owner should know |
| date | the date it was run (ISO) |
| approval_reference | signature or document/ticket reference of the business owner |
