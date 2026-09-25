# RBAC Architecture

How EUISIS decides who may do what, where. Related documents: [default role–permission matrix](default-role-permission-matrix.md) · [RBAC audit report](rbac-audit-report.md).

## 1. The four questions

Every sensitive action answers four questions, and **a permission only answers the first**:

| Question | Answered by | Example |
|---|---|---|
| **What** may this person do? | **Permission** (Spatie, `web` guard) | `employees.update` |
| **Where** may they do it? | **Organization scope**: `UserOrganizationScope` rows, enforced by `OrganizationScopeService` in policies and queries | only the Bole sub-city and its subtree |
| **Whose** record is it? | **Ownership**, enforced by policies (`DailyActivityLogPolicy`, `EpmsAccess::isOwn`, My Portal controllers) | an employee's own daily log |
| **For whom** do they act? | **Team coverage**: Daily Activity reviewer assignments (`DailyActivityReviewerResolver`) and committee membership (`GrievanceCommitteeMember`) | a supervisor's own team; an assigned appeal |

`employees.update` therefore never means "all employees". For an Organizational Admin it means employees inside the assigned organization and its subtree. Every policy and query must also check scope, ownership or coverage. Hiding a button in the frontend is never the control; the backend always re-checks.

## 2. Roles

- **A role describes a capability, never a place.** There is one `Organizational Admin` role; its jurisdiction comes from the user's scope rows. Do not create "Bole HR Admin", "Yeka HR Admin" and so on. The legacy `Sub-city Admin`, `Woreda Admin` and `Institution Admin` roles are exactly that anti-pattern. They are left untouched on existing databases and are no longer seeded.
- **Scope type**: `roles.scope_type` is `global` (city-wide, unrestricted scope) or `scoped` (needs scope rows).
  - A global role can only be assigned by Super Admin or System Admin (`Role::canBeAssignedBy`).
  - Assigning a scoped role requires an organization scope.
- **Protected roles** (`Role::isProtected`: Super Admin, System Admin, City Admin, Public Service Bureau Admin, Security Settings Manager):
  - only a Super Admin may edit them or change their permissions;
  - nobody may delete them;
  - only Super Admin or System Admin may assign them.
- **System roles** (`roles.is_system`, all roles in `DefaultRoleMatrix`) cannot be deleted. Their permissions are kept equal to the matrix by `RolePermissionSeeder`.
- **Custom roles** (created in the UI) are never touched by seeding.

## 3. Super Admin

`Gate::before` (AppServiceProvider) passes every ability for Super Admin, so no explicit grants are needed. The seeders still grant every permission, for visibility.

**Exception: self-protection.** For the abilities `delete`, `forceDelete`, `archive`, `deactivate`, `assignRoles` and `assignOrganizationScope` on the acting user's own account, the gate defers to `UserPolicy`. Nobody, Super Admin included, removes or re-roles themselves.

Actions also keep their own guards, independent of the gate: `DeactivateUserAction`, the last-Super-Admin rule, and the card lifecycle guards. Secrets never become permissions. Password hashes, MFA secrets, national IDs, card token hashes and QR payloads are `$hidden` on their models. API tokens are shown once at issue.

## 4. Single source of truth

```
database/seeders/data/permissions.php   (+ daily-activity, performance, transport files)
        │  name, group, label_en/am, description_en/am, sort_order
        ▼
App\Support\Rbac\PermissionCatalog      ── validates: unique dot names, categorized group, bilingual text
        ▼
App\Support\Rbac\DefaultRoleMatrix      ── role → description (EN/AM), scope, permissions
        │   (EPMS and Daily Activity role sets come from PerformanceRoles / DailyActivityRoles)
        ▼
App\Support\Rbac\RbacSynchronizer       ── the only code that writes RBAC defaults
        ▲            ▲             ▲                          ▲
PermissionSeeder  RoleSeeder  RolePermissionSeeder   migration 2026_09_26_000000 (additive)
```

No other file keeps a permission list. `DatabaseSeeder` calls the seeders instead of its old copy.

## 5. Seeding and deployment

| Command | Effect | Safe to repeat |
|---|---|---|
| `php artisan migrate` | adds role metadata columns; registers new catalog permissions; marks existing default roles as system roles and **adds** missing matrix permissions. Never revokes or creates roles. | yes |
| `php artisan db:seed --class=PermissionSeeder` | creates missing permissions and refreshes labels, descriptions and groups; never deletes | yes |
| `php artisan db:seed --class=RoleSeeder` | creates or describes the default roles, then runs RolePermissionSeeder | yes |
| `php artisan db:seed --class=RolePermissionSeeder` | applies the matrix to default roles (`sync` by default) and prints every added or removed permission | yes |
| `php artisan rbac:report [--json] [--strict]` | read-only: code vs catalog vs matrix vs database | yes |

**Sync mode** is set by `config('rbac.default_role_sync')` (`RBAC_DEFAULT_ROLE_SYNC`):

- `sync` (default): each system role ends with exactly its matrix permissions. Permissions an administrator added by hand to a system role are removed, and the seeder prints them.
- `additive`: only adds. Use it for a first pass on a production database, review `rbac:report`, then switch to `sync`.

To customize a default role, create a custom role (optionally copying the default's permissions) instead of editing the system role.

Nothing ever truncates or deletes roles, permissions or user assignments. Stale permissions are reported, not removed.

## 6. Guards and portal isolation

| Actor | Guard / table | Authorization |
|---|---|---|
| Staff and employees | `web` / `users` | Spatie roles and permissions + scope/ownership |
| Service-provider staff | `provider` / `provider_users` | `provider_role` (owner, manager, operator) + per-user service permission keys (`ProviderUser::canUseServicePermission`), always within the own provider (`ProviderPortalContext`) |
| Cafeteria provider staff | `cafeteria_provider` / `cafeteria_provider_users` | the cafeteria portal's own account rules |
| API clients | Sanctum tokens | token abilities and scopes (`NfcApplicationGate`, `provider.scope`) |

Provider and API identities are not Spatie users. A provider session is not an admin session: admin routes require the `web` guard.

- Admin roles do not grant provider-portal sign-in, because City Admin does not hold `cafeteria-portal.*`.
- The employee self-service role never opens admin management pages. Those check admin permissions the Employee role does not hold.
- My Portal pages are keyed to the linked employee record (`users.employee_id`), not to extra permissions.

## 7. Separation of duties

| Workflow | Kept apart | Enforced by |
|---|---|---|
| Organizational change | requester ≠ reviewer/approver ≠ implementer | disjoint role sets; approving one's own request needs `organizational-change-requests.approve_own`, which no default role holds |
| Hierarchy versions | editing (Structure Implementation Officer) vs publishing (City Admin) | permissions |
| ID cards | preparation and printing (ID Card Officer) vs approval, issue and revocation (ID Card Approver) | permissions |
| Performance | officer prepares; reviewer approves; manager appraises the team; calibrator calibrates; the appeal committee decides | permissions + `EpmsAccess::assertSeparated` (approver ≠ submitter, verifier ≠ enterer) |
| Security | role/permission authoring and security settings (Security Settings Manager) vs city operations (City Admin) | permissions + protected roles |
| API | token management (API Manager) vs organizational administration | permissions |

## 8. Permission discovery

`App\Support\Rbac\PermissionUsageScanner` reads authorization call sites:

- **PHP**: `->can()`, `Gate::…()`, `hasPermissionTo()`, `canAny([...])`, `can:` and `permission:` middleware, `'permission' => …` maps, and scoped checks such as `inScope($user, '…')`.
- **Frontend**: `can('…')` and `permission: '…'`.

`tests/Feature/Rbac/RbacSeedingTest` fails when code checks a permission the catalog does not define.

Names composed at runtime cannot be seen statically. They are listed in `PermissionUsageScanner::DYNAMIC_PREFIXES`: public-site sections, `system-settings.manage{Group}`, and `id_card_templates.{create|update}`.

A new module must:

1. add its permissions to the catalog data file, with group and EN/AM text;
2. check them in policies, routes and services, together with scope;
3. grant them in `DefaultRoleMatrix`;
4. add a migration or seeder run for existing databases.
