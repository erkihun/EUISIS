# Branding — Agelgay · አገልጋይ

| | |
| --- | --- |
| **Brand name** | **አገልጋይ** on Amharic pages, **Agelgay** in Latin script (English pages, e-mail sender, authenticator apps) |
| **Meaning** | "Servant": the civil servant whose purpose is to serve the public |
| **Official English name** | Addis Ababa Integrated Employee Management & Service System |
| **Official Amharic name** | የአዲስ አበባ የተቀናጀ የሰራተኛ አስተዳደርና አገልግሎት ሥርዓት |
| **Short name** | AA-IEMSS |
| **Former names (historical only)** | Addis One (2026-10-12 → 2026-10-21); EUISIS; Employee Unified ID and Service Integration System; Addis Ababa Employee Unified ID & Service Platform; AA Employee ID |

Rebranded to Agelgay / አገልጋይ on 2026-10-21 (approved name), after the
2026-10-12 rebrand from EUISIS to Addis One. Do not combine the brand with
"አዲስ" ("Addis Agelgay" reads as "a new servant"); the city is named by the
official name and the owning institution instead. The product's *technical* identifiers (repository
directory, package name, cookie and cache key prefixes, HMAC salts, API
environment variable names used by integrators, pgBackRest stanza) keep
their historical values on purpose: renaming them would break deployed
integrations, sessions or data, and they are never shown to a person (§4).

## 1. Where the identity lives

| Layer | What | Who changes it |
| --- | --- | --- |
| System Settings › General | `application_name` (Brand Name, Latin script), `application_name_am` (Brand Name, Amharic), `application_short_name` (Official Short Name), `official_name_en`, `official_name_am`, `identity_system_logo`, `favicon`, `seal`, `organization_name` | holders of `system-settings.manageGeneral` (Super Admin, System Admin, System Settings Admin). Organizational Admin cannot. Every change is audited (`setting_updated`, before/after values; uploaded files are stored, not written to the audit row). |
| `config/branding.php` | `APP_NAME`, `APP_NAME_AM`, `APP_SHORT_NAME`, `APP_FULL_NAME_EN`, `APP_FULL_NAME_AM` fallbacks, plus the list of former names | operators, through the environment; lets the app boot before the settings table exists |
| `App\Services\Branding\BrandingService` | the only resolver: settings → config; blank setting = unset; `brandName($locale)` (current locale when null: Amharic → `brandNameAm()`, otherwise `brandNameEn()`), `shortName()`, `officialNameEn/Am()`, `officialName($locale)`, `formalLine()`, `logoUrl()`, `faviconUrl()`, `legacyNames()` | code — never read the keys elsewhere |
| Shared Inertia prop `branding` | `{brandName, brandNameEn, brandNameAm, shortName, officialNameEn, officialNameAm, logoUrl, faviconUrl}` on every page (`brandName` in the request's language) | `HandleInertiaRequests`, once per request |
| `lib/branding.ts`, `useBranding()` | `brandNameFor(locale, settings, shared)` and the boot fallbacks; `useBranding()` reads live settings including the settings preview → shared prop → fallback, and `withBrand()` resolves `{brand}`, `{shortName}`, `{officialName}` | every component that shows the name |
| `useLocale().t` | resolves `{brand}` in every translation to the name in the current language, so a translation never spells the product name | translation files |
| Server translations (`lang/*/assistant.php`, `public_assistant.php`, `intelligence*.php`) and AI prompts (`PromptRegistry`) | name the assistants literally ("Agelgay Assistant", "የአገልጋይ ረዳት"): these arrays have no placeholder support. A brand change in System Settings does not reach them; edit them with the brand | developers |

Caching: settings live on the shared cache store (`system_settings_all`,
`system_settings_public`) with a one-hour TTL and are dropped by
`UpdateSystemSettingsGroupAction` on every save, so a brand change reaches
all nodes without a restart as long as `CACHE_STORE` is shared (redis or
database, see docs/load-balancing.md). `config('app.name')` and the mail
sender name are overridden from the same settings at boot.

## 2. Usage rules

| Context | Use |
| --- | --- |
| Browser title | `{Page} \| አገልጋይ` on Amharic pages, `{Page} \| Agelgay` on English ones (from the shared settings; the build-time `VITE_APP_NAME` is only the pre-render fallback) |
| Sign-in, forgot/reset password, MFA, self-registration, provider portal, guest layout | **አገልጋይ** / **Agelgay** by page language, prominently; official name as subtitle |
| Sidebar (desktop and mobile), public header | **አገልጋይ** / **Agelgay** by page language, then `AA-IEMSS`, then the owning institution (`organization_name`) |
| Module names | "{brand} Assistant", "{brand} Intelligence", "{brand} Help" → "የአገልጋይ ረዳት" / "Agelgay Assistant" and so on |
| Formal documents (generated PDFs, report headers, poster attribution) | `Addis Ababa Integrated Employee Management & Service System (AA-IEMSS)` and the Amharic official name — `BrandingService::formalLine()` |
| E-mail subjects, SMS | the brand in the message's language (`BrandingService::brandName()`); SMS templates take `:app` from the resolver |
| E-mail sender name | **Agelgay** (`config('app.name')`, Latin script so every mail client shows it) |
| TOTP issuer | `config('app.name')` → **Agelgay** for new enrolments; existing authenticator entries keep the name they were enrolled under and keep working (the secret is unchanged) |
| File names of new system reports | not currently prefixed with a system identifier; when one is needed use `AA-IEMSS-…` (§51) |
| HTML `application-name`, favicon | from the resolver; there is no PWA manifest in this product |

## 3. Institution branding is a different brand

The institution an employee belongs to keeps its own name (`name_en` /
`name_am`), logo (`logo_path`) and the ID-card template's colours and seal.
Employee ID cards represent the issuing institution and carry **no** system
attribution — none existed before and none was added (§14, §55). The
institution QR poster shows the institution first and a discreet system
attribution line beneath (§56). The grievance letterhead uses the
institution's header line and falls back to the owning organization name,
never the product name. `BrandingService` deliberately resolves nothing
institution-specific, and `BrandingConfigurationTest` proves that an
institution with its own name and logo does not change the global brand.

## 4. Legacy identifiers kept on purpose

| Identifier | Where | Why it stays |
| --- | --- | --- |
| `euisis_locale` cookie | `SetClientLocale` | renaming logs every visitor out of their language choice; not visible |
| `urn:euisis:nfc-scan` UUID namespace | `NfcController` | changes every derived NFC scan id |
| `euisis:national-id-hash:v1` | `NationalIdHash` HMAC derivation | **changing it would make every stored national-ID hash unmatchable**; never touch |
| `euisis-demo-v1` | `DemoDataset::TAG` | demo-data marker already stored in rows |
| `EUISIS_API_BASE_URL`, `EUISIS_API_TOKEN`, `EUISIS_API_TIMEOUT`, `config/euisis.php`, `EuisisApiClient` | `external-apps/cafeteria-system` (a separate application) | integration contract of a deployed external app; its own UI strings name the platform "EUISIS" and are NEEDS_REVIEW for that application's owners |
| pgBackRest stanza `euisis`, `/var/lib/euisis-backup/status.json` | `config/backup.php` | backup repository identity on the hosts |
| `EUISIS-password-check/1.0` User-Agent | `HibpCompromisedPasswordChecker` | identifier sent to an external service; harmless |
| `EUISIS-Admin-2026!!` | `PasswordIsNotCommon` example, `PasswordPolicyTest` fixture, `docs/password-security-policy.md` | an example of a weak, product-named password; still a good example |
| `euisis` in `security.passwords.context_terms` | `config/security.php` | passwords built from the old name stay weak; `addisone`, `aaiemss`, `iemss` were added |
| Repository directory `EUISIS`, `package-lock.json` name, `loadtest/k6/euisis.js` file name, `.gitignore` entry, `docs/testing.md` pcov path | tooling | technical paths; `composer.json` is still `laravel/laravel` |
| `EUISIS-Security-Emergency` Telegram group | `docs/operations-security-policy.md`, `docs/incident-response-plan.md` | the name of a real operational channel; renaming it is an operations decision (NEEDS_REVIEW) |
| `https://euisis.addisababa.gov.et`, `https://euisis.example.gov.et` | config comments, scripts, docs | example hostnames; the real hostname is an infrastructure decision |
| Design-canvas names ("EUISIS Public Site Redesign", "EUISIS Admin Colorful Cards"), `Button.tsx` note, migration comment of 2026-08-14 | comments | historical references |

## 5. Historical records

Audit log entries, issued documents, exported reports, stored
correspondence and dated assessment documents in `docs/`
(`security-assessment-report`, `security-score-report`,
`production-readiness-score`, `production-readiness-assessment`,
`security-assessment-phase2`, `rbac-security-audit`,
`insa-secure-website-management-audit`,
`security-boundary-hardening-report`, `security-gap-analysis`,
`security-remediation-roadmap`, `daily-activity-production-readiness`,
`organization-design-readiness`, `load-testing` (the 2026-10-11 run),
`requirements-extracted`, `Recommended-Technologies.txt`) keep the name
they were written under. `branding:migrate` never reads them. Code comments
and dated design documents that say "Addis One" are history too and were not
rewritten.

## 6. Moving an existing installation

```bash
# 1. In .env: APP_NAME="Agelgay" and APP_NAME_AM="አገልጋይ" (the command refuses to move
#    a value onto a name that is itself a former one, e.g. an old APP_NAME="Addis One").
php artisan config:clear
php artisan branding:migrate --dry-run   # report only
php artisan branding:migrate             # move defaults, clear caches
php artisan db:seed --class=PermissionSeeder   # permission labels that name the brand
php artisan production:readiness         # "Branding" section
npm run build                            # VITE_APP_NAME, the pre-render title fallback
```

`branding:migrate-addis-one` still works: it is an alias kept for deploy
scripts written for the previous rebrand. The command changes only rows
whose value equals a former default (`general.application_name`,
`general.application_name_am`, `general.application_short_name`, the legacy
demo `branding.app_name_en/am`, the public home intro title); anything an
administrator typed is listed as NEEDS_REVIEW and left alone. It is
idempotent, writes no audit history and touches no historical record.
Seeders are `firstOrCreate` and never overwrite a customised brand.

## 7. BRAND_ASSET_REQUIRED

No approved Agelgay logo or favicon exists in this repository. The shell
renders the generic mark (`ApplicationLogo`) and `public/favicon.ico` until
an approved asset is uploaded under System Settings › General
(`identity_system_logo`, `favicon`). `production:readiness` reports both as
WARN, not as blockers. No logo or seal was invented (§23, §53). Colours are
the existing Appearance settings; no palette was changed (§54).

## 8. Proofs

`tests/Feature/Branding/`: `BrandingConfigurationTest` (defaults, Amharic
and Latin names by page language, override, blank fallback, cache
invalidation, institution separation),
`BrandingSecurityTest` (unauthorised, Organizational Admin, settings
manager + audit), `BrandingUiTest` (static scan of components, translations,
language files and views; sign-in, dashboard shell, public QR pages),
`BrandingDocumentsTest` (poster, report headers, mail, QR and URL
stability), `BrandingMigrationTest` (migration command and its alias,
EUISIS and Addis One defaults, refusal on a stale `.env`, idempotency,
readiness section).
