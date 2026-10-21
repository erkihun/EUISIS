# Agent F — Cross-cutting quality (Amharic, calendar, responsive, dark, a11y, perf)
Account qa.sysadmin6. Scripts 70-quality-*.js; results results/70-quality-*.json; shots/q-*.png.

Verdict: core quality solid. Amharic renders with Abyssinica SIL + Ethiopian calendar on 15/15 reachable pages, 0 mojibake; language/theme persist; no horizontal overflow at 375/768; drawer/menus keyboard+touch operable; axe: 0 critical, 1 serious.

Journeys: Amharic rendering PASS (15/16, /reports 404); language switch PASS w/ defects; responsive PASS; dark mode PASS w/ contrast findings; a11y PASS w/ 1 serious; perf: load < 3 s everywhere, network-idle > 3 s on employees/organizations/org-design reports (uncompressed dev server under load); console: 0 page errors, 0 5xx.

Defects:
- D1 Medium: role names shown in English in Amharic UI (HandleInertiaRequests.php:61 shares raw getRoleNames(); AppHeader.tsx:97; roles table has description_am but no Amharic name).
- D2 Medium: seeded organizations/organization types have NULL name_am (`select name_en,name_am from organizations/organization_types`), so Amharic UI shows English names; OrganizationTypeSeeder defines name_am but firstOrCreate does not backfill.
- D3 Medium: audit log shows raw model names/event keys in Amharic (`OrganizationUnit`, `User #14`, `Service Feedback Organization Qr Generated`) — Pages/AuditLogs/Index.tsx:185-187.
- D4 Medium: dashboard System state card shows raw `NOT_CONFIGURED` (DashboardOverview.tsx:101 key lookup falls back to raw code; i18n/am/backup.ts:11 key under a different path).
- D5 Low: English aria-labels in Amharic UI (`Notifications alt+T`, `Breadcrumb`).
- D6 Low: public complaint page heading bilingual `ቅሬታ · Complaint`.
- D7 Medium: Amharic validation names field in Latin: `የemail መስክ አስፈላጊ ነው።` (lang/am/validation.php attributes lacks `email`, `name`).
- D8 Low: doubled prefix `የየይለፍ ቃል መስክ…` (validation 'required' prefixes የ, attribute already starts with የ).
- D9 Medium: no language/theme switcher on /login, forgot-password, MFA pages (Login.tsx has no LanguageSwitcher).
- D10 Medium: dark-mode contrast < 4.5:1 on active nav/tabs/filter button (#767eac primary-soft; 3.73–4.19).
- D11 Medium (axe serious): `Preview unavailable` red text shown spuriously on /employees/create although /code-rules/preview-code returned 200 — CodeRuleField.tsx:97-119 ignores only AbortError, axios cancellation is CanceledError/ERR_CANCELED; also contrast 4.32.
- D12 Low: `/reports` not a route (404) though referenced.

Observations: O1 tap targets 30–36 px (< 44 px); O2 JS payload ~2.0–2.5 MB uncompressed per cold page, English dictionary 541 KB, rich-text-editor chunk 1.2 MB, 702 JS files 9.96 MB total; O3 cookie/localStorage locale mismatch loads both dictionaries (+790 KB) and an extra Inertia reload; O4 /notifications/feed and code-rule preview requested twice on mount (effect cleanup abort); O5 Ethiopian dates lack `ዓ.ም` suffix required by terminology guide §4 (dateFormat.ts:30); O6 login page has no <main> landmark, 35 Tabs to content on /employees without skip link; O7 /my-portal renders for this account.
Untested: print/PDF outputs; MFA-role pages.
