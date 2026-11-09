# ADR 0026: A complete default role catalog

- Status: accepted (2026-11-02), decided by the System Owner (business-rule choices delegated to the
  implementer and recorded here).
- Context: `App\Support\Rbac\DefaultRoleMatrix` shipped 67 system roles over a catalog of 972
  permissions, but 302 permissions could be reached only through the four administrator roles
  (Super Admin, System Admin, City Admin, Public Service Bureau Admin). Whole domains had no
  working role at all: employee transfers beyond the institution-side approvals, vacancies, public
  feedback handling and the feedback QR, the Situation Room, Intelligence, backups and recovery, the
  court module (63 permissions), code rules, structure master data, institution offices and
  relationships, the help desk, the recycle bin, and the module policy settings. In practice that
  pushes administrators to hand out City Admin for day-to-day work, which is exactly the
  over-privilege the RBAC design (docs/rbac-architecture.md) exists to prevent.

## Decision

1. **Every catalog permission is held by at least one non-administrator default role**, except two
   explicit lists in the matrix:
   - `DefaultRoleMatrix::ADMIN_ONLY` — deliberately reserved for the administrators: `users.delete`,
     `users.viewSensitive`, `recycle-bin.forceDelete`, `cafeteria-portal.impersonate`,
     `code-rules.delete`, `hierarchy-versions.publish`, `hierarchy-versions.archive` (publishing the
     city structure stays with City Admin, docs/rbac-architecture.md §7), and
     `organizational-change-requests.approve_own`, which no role holds.
   - `DefaultRoleMatrix::LEGACY_ALIASES` — names newer permissions replaced (`organizations.manage`,
     `entitlements.manage`, the five legacy `grievances.*` aliases). They stay in the catalog for old
     databases and are granted to nobody; the modules map them to the new sets themselves.
   A test (`tests/Feature/Rbac/RbacSeedingTest.php`) enforces the rule, so a new permission must
   either be granted to a role or be added to one of the two lists on purpose.

2. **Roles follow the existing principles**: a role names a capability, never a place; scope rows say
   where; maker ≠ checker ≠ implementer seats are separate roles; privileged reads (identity,
   precise location, evaluator identity, bench notes) are their own roles; read-only roles write
   nothing. 38 roles are added, 105 in all:

   | Domain | Roles (scope) | Separation kept |
   | --- | --- | --- |
   | Structure | Organization Registrar (scoped), Structure Catalog Manager (global), Code Rule Administrator (global), Functional Supervisor (scoped) | registrar keeps organizations, offices and relationships; units and positions still go through the change-request workflow; hierarchy versions are published by City Admin; code rules are archived, never deleted |
   | Transfers | Transfer Vacancy Officer (scoped), Transfer Approver (scoped), Transfer Final Approver (global), Transfer Administrator (global) | officer announces and screens; the institution approves release or receiving (scope decides which side); the bureau gives final approval and overrides; the administrator executes and keeps settings |
   | Vacancies | Vacancy Officer (scoped), Vacancy Selection Approver (scoped) | drafting and screening vs publishing, selecting and starting the transfer |
   | Public feedback | Public Feedback Coordinator, Handler, Investigator (scoped), Public Feedback Administrator (global) | routing and QR vs handling vs identity and evidence (investigator only) vs city-wide questionnaire and settings |
   | Executive dashboards | Situation Room Viewer, Situation Room Executive, Intelligence Analyst (scoped) | aggregates vs audited drill-down and exports; business analytics vs technical diagnostics (docs/intelligence-architecture.md D-3, closing `NEEDS_POLICY_DECISION_ROLE_GRANTS`) |
   | Platform | Platform Operations Engineer, Intelligence Governance Officer, Help Desk Agent, Workflow Policy Administrator, Recycle Bin Manager (global) | technical health and runbooks vs AI governance vs tickets vs module policy settings vs restoring deleted records |
   | Backup and recovery | Backup Operator, Recovery Requester, Recovery Reviewer, Recovery Approver (global) | four people, as docs/backup-recovery-architecture.md asks |
   | Assessment | Assessment Administrator, Assessment Data Auditor (global) | cycles, policies and finalization vs the oversight officer who reviews, returns and verifies; criterion-level responses and evaluator identity only in the read-only auditor |
   | Grievance | Grievance Records Custodian (scoped) | archive, legal hold and audited reopen, with no part in a decision |
   | Field work | Field Work Location Auditor (scoped) | precise coordinates are a privileged, audited read |
   | Court | Court Viewer, Court Registrar, Court Judge, Court President, Court Inspector, Court Administrator (scoped) | registry registers and assigns; judges draft; the president approves decisions and letters and decides appeals; inspectors inspect; the administrator configures and removes records entered in error; bench notes belong to judges |
   | Services | Service Entitlement Manager (global), NFC Terminal Administrator (scoped) | service catalog and entitlement rules vs transactions; terminals vs credentials |

3. **Existing roles gain the permissions their own workflow was missing**: Structure Implementation
   Officer creates and keeps position establishments, removes edges, exports and sees archived units
   and positions, and opens the Core workspace; Structure Change Reviewer approves position
   establishments; Organizational Admin manages structural offices, profile photos and restores scope
   rows; ID Card Officer exports and verifies, ID Card Approver archives and verifies; Security
   Settings Manager authors custom permissions; System Settings Admin configures the Situation Room;
   API Manager replays integration events and views payloads; Auditor and Report Viewer see dashboard
   reports and the recycle bin (read); Employee applies for transfers and vacancies and withdraws;
   Cafeteria Admin creates cafeteria holidays; the legacy Cafeteria Provider opens the portal.

4. **`core.access` opens the Core workspace for the structure roles too** (Organization Registrar,
   Structure Catalog Manager, Structure Implementation Officer). Each Core page still checks its own
   permission and organization scope, so this changes navigation, not authority.

## Consequences

- `php artisan db:seed --class=RoleSeeder` creates the 38 roles on an existing database and leaves
  users and custom roles untouched (RbacSynchronizer). Nobody is assigned anything by seeding.
- The user role picker groups the new roles under transfers, public feedback, executive dashboards,
  court and platform headings (EN and AM).
- The MFA baseline is unchanged: no new role reaches the City Admin grant size.
- Later work that adds a permission must grant it to a role or list it on purpose; the seeding test
  fails otherwise.

## Not decided here

- Which real people hold which seats: assignment is an administrator's act, per institution.
- Whether `hierarchy-versions.publish` should ever leave City Admin; a later ADR may create a
  publisher seat.
- The court module's own `court_roles` table (admin, staff, viewer) is not replaced; the platform roles
  above decide what a platform account may do when court staff membership opens a file.
