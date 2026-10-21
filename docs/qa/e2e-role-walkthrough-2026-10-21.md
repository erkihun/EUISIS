# Agelgay (EUISIS): role-by-role end-to-end walkthrough, 2026-10-21

Commit under test: `6a942b44` (main). Every role in the system was signed in as its own user in a real Chrome session. Each role opened every page its sidebar offers, was refused pages it does not hold, and signed out. Two cross-role journeys ran through the UI as well.

## 1. Verdict

Every role can sign in, work through its pages and sign out. Authorization holds: no role reached data it should not. Seven defects remain. Two break a module for some roles, three break images or links for many roles, and two are role-design gaps.

| Check | Result |
|---|---|
| Sign-in, all 57 roles (3 with MFA enrolment) | PASS 57/57 |
| Sidebar pages opened in the browser | 1,260 of 1,280 PASS; the 20 failures are defects R3 and R6 |
| JavaScript exceptions across all page loads | 0 |
| Server errors (5xx) logged by the app | 2, both defect R1 |
| Refusal probes (gated pages a role does not hold) | 9,592 requests; 9,498 refused; 88 opened, all by design (see §5); 6 proxy timeouts, re-checked as refused |
| Sign-out through the account menu, then `/dashboard` sends you to login | PASS 57/57 |
| ID card: officer requests, a different person approves | PASS 7/7 steps |
| Employee self-service (My Portal) | PASS 9/9 steps |

## 2. Defects

| ID | Severity | Defect | Where |
|---|---|---|---|
| R1 | High | **The Communication home page returns HTTP 500 for Communication Investigator and Communication Privacy Officer.** Every user who has `communication.access` but not `announcements.view` is affected, and the header chat icon leads there. The fallback `collect()` is a plain collection, and the next line calls `modelKeys()`, which exists only on Eloquent collections. Log: "Method Illuminate\Support\Collection::modelKeys does not exist" (ERR-YAIJFZTLL3AG, ERR-8MEHV2SWUDPP). | `app/Http/Controllers/Communication/CommunicationController.php:48-51` |
| R2 | High | **System Admin (879 permissions) and Public Service Bureau Admin (777) sign in without MFA.** Super Admin, City Admin and Cafeteria Admin are forced to enrol. The baseline list in `MFA_REQUIRED_ROLES` names "HR Manager" and "Security Officer", which do not exist, and omits these two roles. The database list `mfa_required_role_ids` is empty. This is S4 from the earlier report, still open. | `config/security.php:45`, `.env` |
| R3 | Medium | **19 roles have a Dashboard link that opens a 403 page, and its "Go to Dashboard" button loops back to the same 403.** These roles have no `dashboard.view`, so sign-in correctly sends them to My Portal. The sidebar's Dashboard entry has no permission gate, though, and the error page always links to the dashboard route. Officers without an employee record also land on "No employee profile is linked to your account. Contact HR", which is wrong for a staff officer. Affected: all five Performance roles, both Assessment oversight roles, all three Communication roles, all four Transport roles and Organization Transport Officer, Daily Activity Reviewer, Public Site Manager, Cafeteria Provider and the Comm UAT Employee role. | `resources/js/Components/AppSidebar.tsx:718` and `:877`; `resources/js/Pages/Error.tsx:90`; `shots/communication-moderator-*.png` |
| R4 | Medium | **Employee photos are broken wherever the code still builds a public `/storage/...` link.** Photos now live on the private disk, so those links return 403. Seen on the employee list for six roles. By code reading, the same applies to the cafeteria transaction screens and to the provider-portal scan result, where the operator needs the face to check the holder. The `photo_url` accessor already points to the authorized route. The provider portal also needs a photo route that its own guard may use. | `app/Http/Resources/EmployeeResource.php:29`; `app/Http/Controllers/Web/CafeteriaTransactionController.php:321,375`; `app/Http/Controllers/ProviderPortal/ProviderScanController.php:152` |
| R5 | Medium | **ID Card Officer and ID Card Approver see broken photos on the ID card list.** The private-photo route authorizes `view` on the employee, which needs the employee registry permission these roles do not hold. The approver therefore cannot compare the face before approving. This is the same pattern that was fixed for card requests in `a360ab6a`. | `app/Http/Controllers/Web/EmployeePortalReviewController.php:24` |
| R6 | Low | **Organizational Admin's sidebar offers Public Feedback "Settings", and the page refuses it.** The page is citywide-only by design, but the scoped role ships with `service_feedback.settings.manage`. Either remove that permission from the scoped role or hide the entry for scoped users. | `app/Services/ServiceFeedback/PublicFeedbackSettings.php:122`; `app/Support/Rbac/DefaultRoleMatrix.php` (Organizational Admin) |
| R7 | Low | **The Settlement Officer role cannot reach any settlement screen.** It holds only legacy permissions (`transactions.view`, `service-transactions.viewAny`, `providers.viewAny`, `reports.view`). Cafeteria and transport settlements check `cafeteria_settlements.*` and `transport-settlements.*`, so the role sees Dashboard and My Applications only. | `app/Support/Rbac/DefaultRoleMatrix.php:488-489` |
| R8 | Low | **The card-request confirm dialog is not exposed as a dialog.** It has no `role="dialog"` or `aria-modal`, focus stays behind it, and its close button has no accessible name. | `resources/js/Pages/CardRequests/Show.tsx:97-135` |

### Fix status (same day)

All eight are fixed in the working tree. `tests/Feature/RoleWalkthroughFixesTest.php` covers R1 to R7, and the R1 and R5 tests were confirmed to fail on the original code. A browser re-walk of the affected roles confirmed each fix.

| ID | Fix | Verified in the browser |
|---|---|---|
| R1 | Both announcement fallbacks are Eloquent collections. | Investigator and Privacy Officer open the Communication home. |
| R2 | `MfaRequirementService::DEFAULT_REQUIRED_ROLES` lists Super Admin, System Admin, City Admin, Public Service Bureau Admin and Cafeteria Admin. Config, `.env.example` and the docs use it, and a test keeps it in step with the role matrix. **Servers whose `.env` still sets the old list must update `MFA_REQUIRED_ROLES`.** | System Admin and Public Service Bureau Admin are now forced to enrol. |
| R3 | `/dashboard` redirects users without dashboard access to My Portal. The sidebar home, the logo and the breadcrumb root follow the shared `auth.canViewDashboard` flag. Officers without an employee record see "Your work pages are in the menu" instead of "Contact HR". | No Dashboard link remains for these roles; every sidebar page opens. |
| R4 | The employee list uses the authorized photo route. Cafeteria and provider-portal scan results carry a signed link that expires after 10 minutes (`EmployeeScanPhoto`, route `employees.scan-photo`). | Employee list photos load. |
| R5 | New `EmployeePolicy::viewPhoto` lets ID card staff see photos inside their organization scope. | ID card list photos load for Officer and Approver. |
| R6 | Sidebar entries can be marked `citywideOnly`. Public feedback Settings is marked, and `auth.unrestrictedScope` is shared. The permission stays on the role, because it also gates scoped QR and position-service work. | The link no longer shows for Organizational Admin. |
| R7 | Settlement Officer gains read-only cafeteria settlement, transport settlement and invoice permissions. Run `RolePermissionSeeder` on deploy. | Its sidebar lists all three settlement screens, and they open. |
| R8 | The confirm dialog uses Headless UI `Dialog`, which provides the dialog role, aria-modal, focus trap, Escape and a title label. The close button has an accessible name. | Code change; type check passes. |

## 3. Environment findings (not product defects)

- **The local database lagged the shipped role matrix.** Three roles were missing: Transport Dispatcher, Transport Finance Reviewer and Organization Transport Officer. Super Admin, System Admin, City Admin and Public Service Bureau Admin lacked 58 to 61 transport permissions, and Transport Admin lacked 37. I ran `PermissionSeeder`, `RoleSeeder` and `RolePermissionSeeder` before testing. Any server that has not run them since the transport work has the same gap.
- **The local `.env` sets `MFA_ENFORCE=false`**, so nobody is asked for MFA on this machine. The test servers ran with `MFA_ENFORCE=true`, the shipped default.
- **"Comm UAT Employee" exists in the database but not in the role matrix.** It is a leftover UAT role.
- **`public/build` predated the last frontend commit** and was rebuilt before testing.

## 4. Role-by-role results

Scope: global roles were scoped citywide; every other role was scoped to the Public Service Bureau subtree. "Opened" counts pages that loaded with HTTP 200 and no JavaScript exception. "Refused" counts gated pages that returned 403 or redirected away.

| Role | Perms | MFA | Lands on | Sidebar pages opened | Refused | Notes |
|---|---|---|---|---|---|---|
| Administrative Tribunal Officer | 5 | | Dashboard | 8/8 | 181 | |
| API Manager | 15 | | Dashboard | 3/3 | 187 | |
| Assessment Oversight Officer | 12 | | My Portal | 9/10 | 180 | R3 |
| Assessment Report Viewer | 3 | | My Portal | 4/5 | 185 | R3 |
| Auditor | 22 | | Dashboard | 16/16 | 169 | reads transfer settings and applications by URL only (§5) |
| Cafeteria Admin | 68 | yes | Dashboard | 20/20 | 170 | |
| Cafeteria Operator | 12 | | Dashboard | 14/14 | 176 | |
| Cafeteria Provider | 12 | | My Portal | 1/2 | 188 | R3 |
| City Admin | 777 | yes | Dashboard | 176/176 | 8 | R4 |
| Comm UAT Employee | 22 | | My Portal | 1/2 | 188 | R3 |
| Communication Investigator | 2 | | My Portal | 1/2 | 188 | R1, R3 |
| Communication Moderator | 14 | | My Portal | 1/2 | 188 | R3; moderation console works |
| Communication Privacy Officer | 3 | | My Portal | 1/2 | 188 | R1, R3 |
| Daily Activity Reviewer | 9 | | My Portal | 11/12 | 178 | R3 |
| Design Catalog Manager | 16 | | Dashboard | 10/10 | 180 | |
| Disciplinary Committee Member | 3 | | Dashboard | 3/3 | 187 | |
| Disciplinary Decision Authority | 3 | | Dashboard | 3/3 | 187 | |
| Discipline Case Officer | 8 | | Dashboard | 3/3 | 187 | |
| Employee | 43 | | My Portal | 19/19 | 177 | |
| Grievance Administrator | 14 | | Dashboard | 9/9 | 178 | |
| Grievance Committee Chairperson | 19 | | Dashboard | 7/7 | 182 | |
| Grievance Committee Member | 8 | | Dashboard | 7/7 | 182 | |
| Grievance Committee Writer | 10 | | Dashboard | 7/7 | 182 | |
| Grievance Executive Approver | 7 | | Dashboard | 6/6 | 181 | |
| Grievance Officer | 25 | | Dashboard | 12/12 | 178 | |
| Grievance Oversight | 11 | | Dashboard | 10/10 | 178 | |
| Grievance Registry Officer | 6 | | Dashboard | 4/4 | 186 | |
| HR Officer | 101 | | Dashboard | 47/47 | 137 | R4 |
| ID Card Approver | 12 | | Dashboard | 5/5 | 185 | R5 |
| ID Card Officer | 18 | | Dashboard | 8/8 | 183 | R5 |
| NFC Provisioning Officer | 9 | | Dashboard | 6/6 | 183 | |
| Organization Design Approver | 54 | | Dashboard | 28/28 | 162 | |
| Organization Designer | 57 | | Dashboard | 28/28 | 162 | |
| Organization Transport Officer | 17 | | My Portal | 8/9 | 181 | R3 |
| Organizational Admin | 178 | | Dashboard | 67/68 | 121 | R4, R6 |
| Performance Appeal Committee | 2 | | My Portal | 2/3 | 187 | R3 |
| Performance Calibrator | 5 | | My Portal | 6/7 | 182 | R3 |
| Performance Manager | 23 | | My Portal | 15/16 | 172 | R3 |
| Performance Officer | 36 | | My Portal | 17/18 | 173 | R3 |
| Performance Reviewer | 12 | | My Portal | 7/8 | 181 | R3 |
| Public Service Bureau Admin | 777 | **no** | Dashboard | 176/176 | 8 | R2, R4 |
| Public Site Manager | 27 | | My Portal | 2/3 | 187 | R3 |
| Report Viewer | 6 | | Dashboard | 10/10 | 176 | |
| Security Settings Manager | 23 | | Dashboard | 7/7 | 183 | |
| Service Provider Manager | 6 | | Dashboard | 3/3 | 187 | |
| Service Provider User | 6 | | Dashboard | 3/3 | 187 | |
| Settlement Officer | 5 | | Dashboard | 2/2 | 188 | R7 |
| Structure Change Requester | 28 | | Dashboard | 12/12 | 177 | |
| Structure Change Reviewer | 14 | | Dashboard | 8/8 | 180 | |
| Structure Implementation Officer | 32 | | Dashboard | 10/10 | 179 | |
| Super Admin | 879 | yes | Dashboard | 185/185 | n/a | R4 |
| System Admin | 879 | **no** | Dashboard | 185/185 | n/a | R2, R4 |
| System Settings Admin | 16 | | Dashboard | 4/4 | 186 | |
| Transport Admin | 71 | | My Portal | 19/20 | 170 | R3 |
| Transport Dispatcher | 22 | | My Portal | 11/12 | 178 | R3 |
| Transport Finance Reviewer | 14 | | My Portal | 8/9 | 181 | R3 |
| Transport Operator | 7 | | My Portal | 5/6 | 183 | R3 |

## 5. Pages a role may open although its sidebar hides them (observation)

88 probe requests across 12 pages returned 200. I read each controller. Every one accepts a wider permission set than the sidebar entry, on purpose, or filters rows to the viewer. None exposed data outside the role's grant. They are sidebar gaps: users who may read a page cannot find it.

- **Assessment records** opens for 50 roles, but the query returns only the viewer's own records unless they manage assessment forms.
- **Grievance case lists** (assigned, authorized and intake tabs) open for any grievance staff permission, and each tab applies its own permission and scope filter. Without the tab's permission the list is empty.
- **Performance agreements** accept `performance_reports.view`, so Report Viewer, Calibrator and Reviewer can read the list. The sidebar asks for `employee_performance_agreements.manage`.
- **Transfer settings and transfer applications** accept `transfers.viewAny` as read access, so Auditor, HR Officer and Organizational Admin can read them by URL.
- **Organization design overview** gates on more permissions than the sidebar lists.
- **Change-request lists** for Structure Change Reviewer and Implementation Officer, **assessment oversight** for Performance Manager, and **transport overview** for Transport Operator follow the same pattern.

## 6. Cross-role journeys

**ID card request, segregation of duties (verifies `a360ab6a`).** A QA employee in the Public Service Bureau with no card was used, because all three demo employees there already hold cards.

1. ID Card Officer opens New card request; one eligible employee is listed.
2. The officer selects the employee and submits; the request is stored as `submitted`.
3. On the request page the officer sees Verify and Cancel, but no Approve or Reject.
4. A direct POST to approve as the officer returns 403.
5. ID Card Approver opens the same request and is offered Approve and Reject.
6. The approver approves through the confirm dialog. The flash reads "Card request approved. Card is now pending print."
7. The database records the officer as requester and the approver as approver.

**Employee self-service.** The Employee role lands on My Portal and opens My Portal, Employment, Daily Activity, Grievances, Support tickets and Profile. Each loads without errors. The admin pages for employees, users, roles, system settings, audit logs and card requests all return 403.

**Communication module.** Moderator reaches messages and the moderation console and is refused investigations and settings. Investigator and Privacy Officer reach investigations and are refused moderation and settings, but their module home fails with R1.

## 7. Performance

Page times here are upper bounds, not measurements of the product. Four browsers shared six single-threaded PHP servers on one laptop. The slowest page took 14.5 s, and 42 page loads took longer than 8 s, mostly for the two 777-permission admin roles while other roles ran. Use the earlier quiet-box measurement for performance decisions.

## 8. Not covered

- Provider portal users, who sign in on the separate provider guard, were not part of this pass. The earlier report covers them.
- Amharic UI, dark mode and mobile widths were not re-run; this pass used English at 1366 × 900.
- Write actions were exercised only in the two journeys above. Every other role was tested for access and page health, not for its create and update flows.

## 9. Test data and state left behind

- 57 accounts `qa-role-<slug>@example.test` are disabled, with roles, scopes, MFA secrets and sessions removed. They cannot be deleted because audit rows reference them.
- Employee `QA-CARD-0001` in the Public Service Bureau remains, with one approved card request now pending print.
- Role permissions were synced to the shipped matrix (§3); that change is intended to stay.

## 10. Evidence

- `docs/qa/evidence-2026-10-21-roles/aggregate.json` holds per-role results and the cross-role issue index.
- `journeys.json` and `extras.json` hold the journey and Communication module steps.
- `shots/` holds screenshots of R1 and R3, the card journey and landing pages.
- Harness and instructions: `docs/qa/e2e-harness/roles/`.
