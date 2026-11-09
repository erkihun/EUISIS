# Raw Date Display Audit

**Date:** 2026-06-08  
**Auditor:** Claude Code (automated)

## Summary

Audited all `.tsx` files under `resources/js/Pages/` for raw date field access patterns not wrapped in `LocalizedDateDisplay`. Found and fixed **7 files** with raw date rendering.

---

## Files Fixed

### 1. `resources/js/Pages/Vacancies/Index.tsx`
**Issue:** `announcement.application_closes_at ?? '-'` rendered as raw string (ISO datetime).  
**Fix:** Wrapped with `<LocalizedDateDisplay value={announcement.application_closes_at} />`.  
**Import added:** `LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay'`

### 2. `resources/js/Pages/Vacancies/Show.tsx`
**Issue:** `application_opens_at`, `application_closes_at`, `published_at` rendered raw via a `{ label, value }` array map where `value` was a plain string.  
**Fix:** Replaced the array map with explicit `<dl>` items using `<LocalizedDateDisplay ... withTime />` for each datetime field.  
**Import added:** `LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay'`

### 3. `resources/js/Pages/Employees/Show.tsx`
**Issue:** `tr.effective_date ?? '—'` (transfer effective date) rendered as raw string.  
**Fix:** Wrapped with `{tr.effective_date ? <LocalizedDateDisplay value={tr.effective_date} /> : '—'}`.

### 4. `resources/js/Pages/Users/Index.tsx`
**Issue:** `u.last_login_at ?? t('users.notAvailable')` rendered as raw datetime string.  
**Fix:** Wrapped with `{u.last_login_at ? <LocalizedDateDisplay value={u.last_login_at} withTime /> : t('users.notAvailable')}`.  
**Import added:** `LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay'`

### 5. `resources/js/Pages/AdministrativeTribunal/Index.tsx`
**Issue:** `c.hearing_date ?? '-'` and `c.decision_date ?? '-'` rendered as raw strings.  
**Fix:** Wrapped each with `{c.hearing_date ? <LocalizedDateDisplay value={c.hearing_date} /> : '-'}`.  
**Import added:** `LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay'`

### 6. `resources/js/Pages/RecycleBin/Index.tsx`
**Issue:** `new Date(record.deleted_at).toLocaleString()` — used browser's `toLocaleString()` instead of the localized calendar system.  
**Fix:** Replaced with `<LocalizedDateDisplay value={record.deleted_at} withTime />`.  
**Import added:** `LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay'`

### 7. `resources/js/Pages/Cafeteria/Settings/Index.tsx`
**Issue:** Provider user view modal rendered `viewingAssignment.effective_from ?? '—'` and `viewingAssignment.effective_to ?? '—'` as raw strings inside a `{label, value}` array map.  
**Fix:** Extracted the date fields out of the map into dedicated `<div>` blocks using `<LocalizedDateDisplay value={...} />`.  
**Note:** File already imported `LocalizedDateDisplay` — no new import needed.

---

## Files Already Correct (no changes needed)

The following pages were inspected and already use `LocalizedDateDisplay` correctly:

- `AuditLogs/Index.tsx`
- `Cafeteria/EmployeeExclusions/Index.tsx`, `Show.tsx`
- `Cafeteria/Holidays/Index.tsx`
- `Cafeteria/Ledger/Index.tsx`
- `Cafeteria/Reports/Index.tsx`, `Show.tsx`
- `Cafeteria/Settings/Index.tsx`
- `Cafeteria/SubsidyRules/Index.tsx`
- `Cafeteria/Transactions/Index.tsx`, `Show.tsx`
- `CardRequests/Index.tsx`, `Show.tsx`
- `Employees/Show.tsx` (assignments, current_assignment, date_of_birth)
- `GrievanceCommittees/Show.tsx`
- `Grievances/Index.tsx`, `MyGrievances.tsx`, `Show.tsx`
- `HierarchyVersions/Index.tsx`, `Show.tsx`
- `IdCards/Index.tsx`, `Show.tsx`, `PublicVerify.tsx`
- `IsicActivities/Show.tsx`
- `Occupations/Show.tsx`
- `Organizations/Show.tsx`
- `OrganizationTypes/Show.tsx`
- `OrganizationUnitTypes/Show.tsx`
- `OrganizationUnits/Show.tsx`
- `PositionEstablishments/Index.tsx`, `Show.tsx`
- `PrintBatches/Index.tsx`, `Show.tsx`
- `ProviderPortal/Transactions/Index.tsx`, `Show.tsx`
- `Public/TransferAnnouncements/Show.tsx`, `Apply.tsx`
- `Public/TransferAnnouncements.tsx`
- `ServiceProviderUsers/Show.tsx`
- `Transfers/Announcements/Index.tsx`, `Show.tsx`
- `Transfers/Applications/Index.tsx`, `Show.tsx`
- `VacancyApplications/Index.tsx`, `MyApplications.tsx`, `Show.tsx`
- `Employee/Portal.tsx`, `MyTransferApplications.tsx`

## Pages Without Date Fields (no changes needed)

- `Organizations/Index.tsx` — no date fields rendered (tree view only)
- `Employees/Index.tsx` — no date fields in the list (name/status/org/position only)
- `Positions/Show.tsx` — no date fields
- `Cafeteria/Providers/Show.tsx` — `created_at` in type but not rendered

## Notes

- Form inputs (`<input type="date" value={data.some_date} ...>`) are intentionally excluded — these bind form state, not display values
- `ProviderPortal/Transactions/Index.tsx:342` uses `toLocaleTimeString()` for time-only display alongside `LocalizedDateDisplay` — acceptable pattern
- No Vitest/Jest setup found in the project, so JS unit tests for `formatDateDisplay` were not added
