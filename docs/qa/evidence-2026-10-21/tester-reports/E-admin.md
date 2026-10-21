# Agent E — Platform administration (Users, Roles, Profile, Settings, Audit, Recycle bin, API, Code rules, Dashboard)
Account qa.sysadmin5 (+ qa.reportviewer, qa.auditor). Scripts 40-admin-*.js (92 steps); results/40-admin-*.json; shots/40-*.png.

Verdict: core administration works (user lifecycle, password policy, forced first-login change, role/permission propagation, Super Admin guards, settings save/revert + audit, Sanctum scopes, recycle-bin restore, other-sessions sign-out). Not release-clean: user edit form never displays server validation errors (High); deactivated account with pending forced password change can still sign in (Medium); non-numeric ids → 500 (Medium); user/role audit rows lack IP/UA (Medium); dashboard employee KPI undercounts (Medium).

Journeys: 1 Users PASS with defects (weak passwords refused with specific reasons; NID + phone encrypted at rest; self-deactivate refused; last Super Admin protected; photo validation; first login forced change; HR Officer sees only HR pages); 2 Roles PASS (QA Role propagation 403→200→403; Super/System Admin roles not editable/deletable); 3 Profile PASS (wrong current pw, policy, other sessions signed out, audit redacts phone); 4 Settings PASS (13 tabs + 8 sub-routes 200; short name saved/reverted with audit; max_login_attempts bounds; MFA tab: required roles empty, System Admin addable); 5 Audit PASS (actor/old/new, no clear-text PII) with D5; 6 Recycle bin: users/roles not recyclable by design, code rules restore OK; 7 API PASS (token shown once, hash stored, scope 403 endpoint_not_allowed, revoke → 401); 8 Code rules PASS with D8; 9 Dashboard PASS with D6; 10 Amharic PASS with D10.

Defects:
- D1 High: User edit form never shows server validation errors (self status=Inactive, unchecking Super Admin, bad photo → silent). Users/Edit.tsx:140-162 submits with router.post but renders form.errors from an unrelated useForm (Create.tsx uses form.post correctly).
- D2 Medium: deactivated account with pending forced password change can still sign in and set a password (login succeeds → /change-password; audit user_logged_in while inactive). LoginRequest::authenticate has no status check; password.forced* routes bypass EnsureAdminAccess.
- D3 Medium: /users/abc/edit, /users/null/edit, /roles/undefined/edit → HTTP 500 "invalid input syntax for type bigint" (ERR-7SAOVOGRVYDS, ERR-LWF9ZUNEQZUH). Routes lack whereNumber.
- D4 Low: national ID shown unmasked on user edit form to any users.update holder; users.viewSensitive not consulted (UserController::edit).
- D5 Medium: audit rows for user/role events have NULL request_ip/user_agent (Create/Update/Deactivate/RestoreUserAction, AssignRolesAction, RoleController call WriteAuditLogAction without request).
- D6 Medium: dashboard Active Employees = 3 while DB has 5 active (employees without current assignment excluded in non-global scope path, DashboardMetricService::employeeQuery ~810-824).
- D7 Low: max_login_attempts limits inconsistent (UI max 20 via SystemSettingsRegistry.php:573, server max 50 UpdateSecuritySettingsRequest.php:39).
- D8 Low: code rule in use archived without confirmation/in-use warning; deletion_reason stored as literal "undefined" (Show.tsx:90).
- D9 Low: 2 MB photo → generic "failed to upload" (app allows 4096 KB, PHP limit 2M).
- D10 Low: Amharic leakage — raw keys codeRules.entityTypes.* on /code-rules; English event labels/model names on /audit-logs; untranslated aria-labels.

Observations: O1 permission audit-logs.viewAny grants nothing (AuditLogPolicy checks audit.view); O2 MFA_REQUIRED_ROLES names non-existent roles; System Admin not MFA-required by default; O3 unchecking all roles sends no roles[] → silently unchanged with success flash; O4 API app delete via UI did not remove app in one run (not retried); O5 dashboard 4.9 s admin / 8.2 s Report Viewer (networkidle, dev server under load); O6 log shows the cache_locks 25P02 500s and SMTP refused for new-user mails (silent).
State left: qa.user.one@demo.local (id 25, HR Officer), orphan id 24 inactive; everything else reverted.
