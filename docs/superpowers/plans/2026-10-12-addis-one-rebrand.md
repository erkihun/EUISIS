# Addis One Rebrand Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebrand the product from "EUISIS / Employee Unified ID and Service Integration System" to **Addis One** (brand), **AA-IEMSS** (short), **Addis Ababa Integrated Employee Management & Service System** / **የአዲስ አበባ የተቀናጀ የሰራተኛ አስተዳደርና አገልግሎት ሥርዓት** (official), through one resolver, without touching schema, routes, namespaces, QR tokens or historical records.

**Architecture:** The product already resolves its name from System Settings (`general.application_name` → `config('app.name')` at boot → shared Inertia `settings` prop, `app.name` / `app.short_name`). The rebrand adds two official-name fields to the same group, a `config/branding.php` fallback layer, one `BrandingService` that resolves brand → short → official names → logo/favicon/colours with settings-over-config priority, a shared Inertia `branding` prop plus a `useBranding()` hook, and replaces every hard-coded legacy string in auth pages, layouts, PDFs, SMS templates and public copy with the resolved values. An idempotent `branding:migrate-addis-one` command moves stored defaults (not customised values, not history) and clears the shared settings cache.

**Tech Stack:** Laravel 12, Inertia React/TS, Pest, Spatie permissions, SystemSettingsRegistry/Service, `@euisis/ui`.

**Spec:** the user's rebrand brief of 2026-10-12 (§1–§63), reproduced in the ledger of this plan's workspace.

## Global Constraints

- Brand name `Addis One`; short name `AA-IEMSS`; official EN `Addis Ababa Integrated Employee Management & Service System`; official AM `የአዲስ አበባ የተቀናጀ የሰራተኛ አስተዳደርና አገልግሎት ሥርዓት` (verbatim).
- Centralise; no new literal brand strings in React components (i18n and the branding prop only).
- No table, namespace, class, route or API renames for cosmetics; no QR/token/code regeneration; no history rewrite; no destructive operations; no invented logo (report BRAND_ASSET_REQUIRED).
- System brand ≠ institution brand (institution name/logo/colours untouched; ID cards keep institution identity).
- Patch-stage shared files touched by the user's uncommitted WIP; `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.

## Review Focus

1. A tenant who already customised `application_name` must keep their value after `branding:migrate-addis-one` (only rows equal to a known legacy default move).
2. The TOTP issuer changes for new enrolments only; existing authenticator entries keep working (secret unchanged).
3. `config:cache`d nodes must pick the new name from settings at boot without a restart after a settings change (the boot override reads the shared cache).
4. The public home copy (`home.ts`) must not read "What is Addis One?" with stale answers describing an ID-card-only system.
5. `MAIL_FROM_NAME` fallback must not become empty when APP_NAME is unset.

---

### Task RB-1: Branding configuration, resolver, shared prop, hook (commit 1)

Files: `config/branding.php` (new), `config/app.php` (name default), `.env.example`, `app/Services/Branding/BrandingService.php` (new), `app/Services/SystemSettings/SystemSettingsRegistry.php` (fields `official_name_en`, `official_name_am`; new defaults and labels for `application_name` / `application_short_name`), `PublicSettingsService` (`app.official_name_en/am`), `AppServiceProvider` (config override uses the resolver), `HandleInertiaRequests` (`branding` shared prop), `resources/js/hooks/useBranding.ts` (new), `resources/js/app.tsx` (title pattern `{Page} | Addis One` from the prop), `resources/views/app.blade.php` (title, `application-name`, favicon from settings, loader), `resources/views/errors/503.blade.php` (maintenance), `config/mail.php` (from-name fallback). Tests: `tests/Feature/Branding/BrandingConfigurationTest.php` (brief §36 1–7), `BrandingSecurityTest.php` (§41 22–24).

### Task RB-2: Auth, layouts, public pages, dashboard (commit 2)

Files: `Login.tsx`, `ForgotPassword.tsx`, `ResetPassword.tsx`, `MfaChallenge.tsx`, `Register.tsx`, `ProviderAuthLayout.tsx`, `GuestLayout.tsx`, `AppSidebar.tsx`, `PublicHeader.tsx`, `PublicFooter.tsx`, `Welcome.tsx`, `Pages/Error.tsx`, i18n `home.ts`, `courtCases.ts`, `fieldWork.ts`, `apiManagement.ts` (EN/AM), `PublicSiteDefaultsSeeder` intro title. Tests: `tests/Feature/Branding/BrandingUiTest.php` (§37 8–13), update `PublicSitePagesTest` expectation.

### Task RB-3: Documents, e-mail, SMS, notifications, exports (commit 3)

Files: `resources/views/pdf/feedback-qr.blade.php`, `organization-feedback-qr.blade.php` (system attribution line from the resolver), `daily-activities/report-pdf.blade.php`, `cafeteria/exports/transaction-statement.blade.php` (already dynamic — verify), `GrievanceCorrespondenceService` fallback, `BackupHealthNotification` subject, `lang/{en,am}/auth.php` + `grievances.php` SMS strings with `:app`, their callers, `MfaController` issuer fallback, `typography-test.blade.php`. Tests: `BrandingDocumentsTest.php` (§38 14–18), QR stability (§39 19–21) reuse `PublicFeedbackQrStabilityTest` plus a rebrand-specific assertion.

### Task RB-4: Data migration command, production check, docs, final search (commit 4)

Files: `app/Console/Commands/BrandingMigrateAddisOne.php` (`branding:migrate-addis-one`: moves legacy defaults in `system_settings` general group and legacy `branding` group rows and the public-site intro title when equal to a known legacy value; clears cache; reports remaining legacy strings in current settings/public-site content; never touches audit logs), `ProductionReadiness` "Branding" section (settings resolve, no legacy brand in current settings, logo/favicon configured → WARN `BRAND_ASSET_REQUIRED`), `docs/branding.md`, `README.md`, docs pass (present-product references), `scripts/smoke-test.sh`, `DatabaseSeeder` legacy branding defaults. Tests: `BrandingMigrationTest.php`. Final legacy search report in the readiness section of `docs/branding.md`.
