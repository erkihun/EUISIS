# Page Performance Audit

**Date:** 2026-06-08  
**Auditor:** Claude Code (automated)

---

## Part 2a — Backend N+1 and Over-fetching

### EmployeeController — `index()` [FIXED]

**File:** `app/Http/Controllers/Web/EmployeeController.php`

**Issue:** `Employee::query()->...->get()` loaded ALL matching employees without pagination. In organizations with hundreds of employees, this would:
- Load all rows into memory
- Serialize the full collection into the Inertia response
- Send a large JSON payload to the browser on every page visit

**Fix:** Changed to `->paginate(50)->withQueryString()`. The collection is extracted via `->getCollection()` to keep the existing `EmployeeResource` resolution pattern. Added a `employees_pagination` prop containing `{current_page, last_page, per_page, total}`.

**Frontend:** Updated `resources/js/Pages/Employees/Index.tsx` to accept the new `employees_pagination` prop and display "Page X / Y · Z results" with Prev/Next buttons when `last_page > 1`.

---

### OrganizationController — `index()` [NOTED, no change]

**File:** `app/Http/Controllers/Web/OrganizationController.php`

**Issue:** `HierarchyVersion::query()->orderByDesc('created_at')->get()` loads all hierarchy versions on the index page. In practice, this table will have very few rows (< 50), so this is acceptable.

**Organizations tree:** The organization tree is built from a scoped service that already filters by accessible IDs, not a full unbounded table scan.

**No change made** — acceptable given typical data volumes.

---

### OrganizationController — `show()` [NOTED, no change]

The `show()` method does `$organization->load(['type', 'mergedInto', 'nameHistories'])` which is eager-loading. No N+1 issue.

---

### OrganizationUnitController — `index()` [NOTED, no change]

Organizations are loaded with `->select([...])` and only needed columns. Edges are loaded separately with only the FK columns. No N+1.

---

### PositionController — `status()` [NOTED, no change]

Loads all positions matching filters plus counts — uses `->get()` without pagination, but the status page is a summary overview. Given positions are organization-scoped, this is bounded in practice.

---

## Part 2b — HandleInertiaRequests Middleware [PARTIALLY FIXED]

**File:** `app/Http/Middleware/HandleInertiaRequests.php`

### Issues Found

1. **`is_employee_user`** — called `$user->employee()->exists()` on every single page load for authenticated users. This is an extra database query per request.

2. **`announcement_count`** — called `TransferAnnouncement::where(...)->count()` on every page load. Another extra query per request.

3. **`auth.permissions`** — calls `$user->getAllPermissions()->pluck('name')->toArray()`. Spatie Permission caches this per-user per request. Acceptable.

4. **`settings`** — delegated to `SystemSettingsService::getPublicSettings()` which already uses `Cache::remember(self::CACHE_KEY_PUBLIC, 3600, ...)`. No issue.

### Fixes Applied

- `resolveIsEmployeeUser()`: Wrapped with `Cache::remember("user_{$user->id}_is_employee", 300, ...)` — 5 minute cache per user.
- `publishedAnnouncementCount()`: Wrapped with `Cache::remember('published_transfer_announcement_count', 60, ...)` — 1 minute cache.

### Shared Props Assessment

| Prop | Cost | Assessment |
|------|------|------------|
| `auth.user` | Free (already loaded) | OK |
| `auth.roles` | Free (Spatie cached) | OK |
| `auth.permissions` | Spatie cached | OK |
| `settings` | Cached 1h | OK |
| `announcement_count` | DB count — now cached 1m | FIXED |
| `is_employee_user` | DB exists — now cached 5m | FIXED |
| `flash` | Session read | OK |

---

## Part 2c — Frontend Bundle

**Files:** `vite.config.js`, `package.json`, `resources/js/app.tsx`

### Assessment

**Bundle splitting is already well-configured:**
- `app.tsx` uses `import.meta.glob('./Pages/**/*.tsx')` which automatically code-splits every page into its own chunk. Heavy pages are never loaded until navigated to.
- `vite.config.js` has `manualChunks` only for `ConfirmProvider` and `ToastProvider` to avoid React context duplication — correct.

### Heavy Libraries

| Library | Size | Used in | Auto-split? |
|---------|------|---------|-------------|
| `recharts` | ~500KB | `Cafeteria/Dashboard.tsx` | Yes (page-level) |
| `html5-qrcode` | ~200KB | `Cafeteria/Scan.tsx`, `Cafeteria/MobileScan.tsx` | Yes (page-level) |
| `html2canvas` + `html-to-image` | ~150KB | ID card print pages | Yes (page-level) |
| `@tanstack/react-table` | ~50KB | Multiple pages | Shared chunk |
| `qrcode.react` | ~30KB | ID card pages | Yes (page-level) |

**No changes needed** — all heavy libraries are only imported in page-level components that are already auto-split by Vite's glob import.

### Recommendations (not implemented — no breaking changes risk)

1. Add `build.sourcemap: false` in production to reduce build size (currently not set).
2. Consider `build.rollupOptions.output.manualChunks` to extract `@tanstack/react-table` into a shared vendor chunk if it's used in many pages.

---

## Summary of Changes Made

| Area | File | Change |
|------|------|--------|
| Performance | `app/Http/Controllers/Web/EmployeeController.php` | Added `->paginate(50)` for employee list |
| Performance | `app/Http/Middleware/HandleInertiaRequests.php` | Cached `is_employee_user` (5m) and `announcement_count` (1m) |
| Frontend | `resources/js/Pages/Employees/Index.tsx` | Added `employees_pagination` prop + page nav UI |
