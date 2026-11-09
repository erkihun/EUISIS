# RBAC & Authorization Security Audit

**Date:** 2026-07-16
**Scope:** Roles, permissions, policies, guards, middleware, organization scopes, and UI authorization across the EUISIS platform (Laravel 12 + Inertia/React).
**Auth stack:** `spatie/laravel-permission` (web guard), a separate `provider` guard for the cafeteria/transport provider portal, Sanctum for the `/api/v1` machine-to-machine surface.

---

## 1. Executive summary

The application has a **broad and generally sound** authorization layer: 60+ policies are registered, the admin area is gated by `auth + verified + mfa + admin.access`, the provider portal is isolated on its own guard, and an `OrganizationScopeService` centralises data-scoping. A `Gate::before` hook grants Super Admin everything.

However, the audit found a small number of **high-severity privilege-escalation holes** where the backend trusts the *actor's permission* but never checks the *privilege of the thing being granted*. Concretely, a delegated admin who holds `users.assignRoles` or `roles.create`/`roles.update` could grant **Super Admin** — or any permission they do not themselves hold — to any account, including themselves, and thereby take over the platform. A handful of Transport admin pages also shipped with **no authorization at all**.

The fixes below close these without renaming any permission key or changing the provider/employee/report/localization behaviour.

---

## 2. Findings

Severity: 🔴 critical · 🟠 high · 🟡 medium · 🟢 low/informational

### 2.1 Privilege escalation (Part 5)

| # | Sev | Finding | Location |
|---|-----|---------|----------|
| E1 | 🔴 | **Any actor with `users.assignRoles` can assign the Super Admin role.** `AssignRolesAction` and `UpdateUserAction` only block *removing* the last Super Admin; nothing prevents *granting* Super Admin. A delegated admin could self-elevate. | `app/Actions/Users/AssignRolesAction.php`, `app/Actions/Users/UpdateUserAction.php` |
| E2 | 🔴 | **Any actor with `roles.create`/`roles.update` can attach permissions they do not themselves hold** (e.g. create a role with `system-settings.manageSecurity` then assign it to self). No "actor must hold the permission being granted" check. | `app/Http/Controllers/Web/RoleController.php`, `RoleStoreRequest`, `RoleUpdateRequest` |
| E3 | 🟠 | **A non-Super-Admin with `users.update` can edit a Super Admin account** (change email/password → account takeover). `UserPolicy::update` checks only `users.update`, ignoring the target's privilege. | `app/Policies/UserPolicy.php` |
| E4 | 🟠 | **A scoped admin can grant a `citywide` organization scope**, escalating a user from a single org to all orgs. `ValidatesAssignableOrganization` guards `organization_id` but a `citywide` scope has `organization_id = null` and bypasses the check entirely. | `StoreUserOrganizationScopeRequest`, `UpdateUserOrganizationScopeRequest` |
| E5 | 🟢 | Self-role edit / self-deactivate / last-super-admin removal are **already blocked** (`UserPolicy::archive/delete`, `UpdateUserAction`). Self-account delete is disabled in `ProfileController::destroy`. No change needed. | — |

### 2.2 Missing controller-level authorization (Parts 2 & 5)

| # | Sev | Finding | Location |
|---|-----|---------|----------|
| A1 | 🟠 | **Transport admin pages have no authorization.** `TransportReportController::index`, `TransportScanController::index`, and all `index/create/edit` methods of the Transport Driver/Route/Vehicle/Pass/Provider controllers render for any authenticated admin. Only the `store/update` FormRequests check a permission. | `app/Http/Controllers/Transport/*` |
| A2 | 🟠 | **Transport settings read/write is ungated.** `TransportSettingsController::index/update` writes `system_settings` rows with no permission check. | `app/Http/Controllers/Transport/TransportSettingsController.php` |
| A3 | 🟢 | API v1 controllers have no `$this->authorize`, but are protected by `auth:sanctum + throttle:api + provider.scope` middleware, which validates the Sanctum token ability and organization scope. Acceptable — informational only. | `app/Http/Controllers/Api/V1/*`, `routes/api.php` |
| A4 | 🟢 | Employee-portal (`EmployeePortalController`) and Profile controllers have no `authorize` calls, but every method scopes strictly to `$request->user()->employee` / the authenticated user, so no cross-tenant access is possible. Acceptable. | `EmployeePortalController`, `ProfileController` |

### 2.3 Permission consistency (Part 4)

| # | Sev | Finding | Location |
|---|-----|---------|----------|
| P1 | 🟡 | **Grievance & tribunal permissions are used in code but never seeded**: `grievances.manage`, `grievances.committee`, `grievances.chairperson`, `grievances.manager`, `grievances.tribunal`, `grievances.view`. The module's policies deny everyone (except Super Admin via `Gate::before`) until an admin manually creates the keys. | `GrievancePolicy`, `GrievanceCommitteePolicy`, seeders |
| P2 | 🟢 | Naming is mostly consistent `module.action`. A few legacy aliases (`organizations.manage`, `cards.manage`, `audit.view`) coexist with granular keys; kept intentionally for backward compatibility. No rename performed. | `PermissionSeeder` |

### 2.4 Organization scope (Part 3)

| # | Sev | Finding | Location |
|---|-----|---------|----------|
| S1 | 🟢 | `OrganizationScopeService` correctly implements `accessibleOrganizationIds` (Self / Subtree via closure paths / Citywide) and `canAccessOrganization`. Employee, Organization, and scope-assignment controllers honour it. | `OrganizationScopeService` |
| S2 | 🟡 | `canAccessOrganization` returns **`true` for any user with no scope records**. This is the documented "unrestricted staff" default and matches the `isNotEmpty()` guards used app-wide, but it means a newly created admin with no scope sees everything. Behaviour retained (changing it is a product decision), documented here. | `OrganizationScopeService::canAccessOrganization` |
| S3 | 🟢 | E4 (citywide scope escalation) is the one real scope-assignment bypass; fixed in §3. | — |

### 2.5 Routes / middleware (Part 1)

- Every admin route is wrapped in `['auth','verified','mfa','admin.access']` (or `['auth','admin.access']` for the employee portal / profile, which deliberately skip the MFA/verify gate). No authenticated admin route is missing the auth stack.
- Public routes (`/`, `/announcements`, `/verify`, `/services`, `/support`, `/verify/card/{uuid}`) are intentionally unauthenticated and read-only or throttled.
- Provider portal is isolated on `auth:provider` + `provider.portal` + context middleware. `EnsureAdminAccess` redirects `user_type = provider` users out of the admin area (portal separation ✔, Part 5 #13/#14).
- Login/register/password-reset are `guest`-gated and throttled.

### 2.6 Mass assignment (Part 5 #16)

- `User::$fillable` does **not** include `role_id`, `permissions`, `is_super_admin`, or `organization_scope_ids` — role/scope assignment goes through dedicated actions/relations. ✔
- `UserStoreRequest`/`UserUpdateRequest` validate `roles.*` against `exists:roles,name` and never mass-assign roles into the model. ✔

---

## 3. Fixes applied

1. **E1 — Block granting Super Admin without being Super Admin.** Added a shared `GuardsSuperAdminAssignment` concern used by `AssignRolesAction` and `UpdateUserAction`: if the resulting role set adds `Super Admin` and the actor is not a Super Admin, a `ValidationException` is thrown (`users.cannot_assign_super_admin`).

2. **E2 — Actors may only grant permissions they hold.** `RoleStoreRequest`/`RoleUpdateRequest` now reject any permission in the payload that the actor does not personally have (Super Admin unrestricted). Message: `roles.cannot_grant_unheld_permission`.

3. **E3 — Protect Super Admin targets.** `UserPolicy::update` now denies a non-Super-Admin actor editing a Super Admin target (self excluded).

4. **E4 — Block scoped admins granting citywide scope.** Both scope FormRequests now reject `scope_type = citywide` (and `subtree` on an org outside the actor's set) unless the actor is unrestricted (Super Admin / City Admin / no explicit scope). Message: `users.organization_scope_citywide_forbidden`.

5. **A1/A2 — Gate the Transport admin controllers.** Added `authorize`/`abort_unless` permission checks (`transport-reports.view`, `transport-settings.view/update`, `transport-*.viewAny/create/update`) to every previously-ungated Transport admin method.

6. **P1 — Seed grievance/tribunal permissions** in `PermissionSeeder` legacy list and grant them to the appropriate roles.

7. **Audit logging** already covers role/permission/user-role/scope/security changes via `WriteAuditLogAction`; the new denials reuse the existing `PermissionChanged`/`UserOrganizationScopeAssigned` audit patterns.

8. **Localization** — added EN/AM strings for every new denial message.

9. **Tests** — added `tests/Feature/Rbac/` covering privilege escalation, portal separation, scope enforcement, and page-access denial.

---

## 4. Remaining risks / notes

- **S2** (unscoped admin sees all orgs) is intentional; if the deployment wants deny-by-default, flip `canAccessOrganization` to return `false` when a user has no scope *and* is not Super/City Admin — that is a product decision, not applied here.
- Grievance permissions are now seeded but roles must be re-seeded (`php artisan db:seed --class=PermissionSeeder` then `RoleSeeder`) for existing environments.
- The `provider` guard's `canUseServicePermission` path (used by Transport FormRequests) is unchanged; provider users still reach their own portal endpoints only.
