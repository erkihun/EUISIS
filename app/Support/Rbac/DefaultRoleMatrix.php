<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Support\DailyActivity\DailyActivityRoles;
use App\Support\Performance\PerformanceRoles;
use InvalidArgumentException;

/**
 * Default system roles and their permissions (docs/default-role-permission-matrix.md).
 *
 * Roles say what someone may do; OrganizationScopeService says where. So
 * there is one Organizational Admin role, scoped per user, never "Bole HR
 * Admin" / "Yeka HR Admin". Team rights also need a reviewer assignment,
 * committee rights a committee membership, own rights ownership.
 *
 * Roles listed here are system-managed: RolePermissionSeeder keeps their
 * permissions equal to this matrix. Roles created in the UI are custom and
 * never touched by seeding.
 */
final class DefaultRoleMatrix
{
    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_SCOPED = 'scoped';

    /**
     * Withheld from the city-wide operational admins: credential-bearing
     * settings, role/permission authoring, API credentials, irreversible
     * deletion, impersonation, and provider-portal sign-in.
     */
    private const CITY_ADMIN_WITHHELD = [
        'system-settings.manageSecurity', 'system-settings.manageEmail', 'system-settings.manageSms',
        'system-settings.manageTelegram', 'system-settings.testNotificationChannels',
        'roles.create', 'roles.update', 'roles.delete', 'roles.assignPermissions',
        'permissions.create', 'permissions.update', 'permissions.delete',
        'recycle-bin.forceDelete', 'users.viewSensitive',
    ];

    private const CITY_ADMIN_WITHHELD_MODULES = ['api_management', 'cafeteria-portal', 'provider-cafeteria-transactions', 'provider-cafeteria-payment-claims'];

    private const STRUCTURE_READ = [
        'organizations.viewAny', 'organizations.view',
        'organization-types.viewAny', 'organization-types.view',
        'organization-units.viewAny', 'organization-units.view',
        'organization-unit-types.viewAny', 'organization-unit-types.view',
        'positions.viewAny', 'positions.view',
        'occupations.viewAny', 'occupations.view',
    ];

    private const CHANGE_REQUESTER = [
        'organizational-change-requests.view_own', 'organizational-change-requests.create',
        'organizational-change-requests.update_draft', 'organizational-change-requests.submit',
        'organizational-change-requests.cancel_own', 'organizational-change-requests.resubmit',
        'organization-units.request_create', 'organization-units.request_update',
        'organization-units.request_move', 'organization-units.request_deactivate',
        'positions.request_create', 'positions.request_update', 'positions.request_move',
        'positions.request_increase', 'positions.request_abolish',
    ];

    private const PUBLIC_SITE_MODULES = [
        'public_site', 'public_home', 'public_announcements', 'public_services', 'public_support',
        'public_navigation', 'public_footer', 'public_branding', 'public_seo', 'public_site_settings',
    ];

    /** @var array<string, array{description_en: string, description_am: string, scope: string, permissions: list<string>}>|null */
    private static ?array $roles = null;

    /** @return array<string, array{description_en: string, description_am: string, scope: string, permissions: list<string>}> */
    public static function roles(): array
    {
        return self::$roles ??= self::build();
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::roles());
    }

    /** @return list<string> */
    public static function permissionsFor(string $role): array
    {
        return self::roles()[$role]['permissions'] ?? throw new InvalidArgumentException("Unknown default role: {$role}");
    }

    /**
     * Problems (empty = valid): unknown permission names, duplicates, bad scope.
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        $problems = [];
        foreach (self::build(validate: false) as $role => $definition) {
            if (! in_array($definition['scope'], [self::SCOPE_GLOBAL, self::SCOPE_SCOPED], true)) {
                $problems[] = "{$role}: invalid scope {$definition['scope']}";
            }
            if (trim($definition['description_en']) === '' || trim($definition['description_am']) === '') {
                $problems[] = "{$role}: missing description";
            }
            foreach (array_count_values($definition['permissions']) as $name => $count) {
                if ($count > 1) {
                    $problems[] = "{$role}: duplicate {$name}";
                }
                if (! PermissionCatalog::has($name)) {
                    $problems[] = "{$role}: unknown permission {$name}";
                }
            }
        }

        return $problems;
    }

    /** @return array<string, array{description_en: string, description_am: string, scope: string, permissions: list<string>}> */
    private static function build(bool $validate = true): array
    {
        $all = PermissionCatalog::names();
        $cityAdmin = array_values(array_diff($all, self::CITY_ADMIN_WITHHELD, PermissionCatalog::matching(...self::CITY_ADMIN_WITHHELD_MODULES)));

        $roles = [
            // ── Administration ────────────────────────────────────────────────
            'Super Admin' => self::role(self::SCOPE_GLOBAL, $all,
                'Full control of every module and organization. Also passes every check through Gate::before; audit, immutable history and self-protection rules still apply.',
                'በሁሉም ሞጁሎች እና ተቋማት ላይ ሙሉ ቁጥጥር። ኦዲት፣ የማይቀየር ታሪክ እና ራስን የመጠበቅ ደንቦች አሁንም ይሠራሉ።'),
            'System Admin' => self::role(self::SCOPE_GLOBAL, $all,
                'Technical platform owner with every permission (without the Super Admin gate bypass). Assign sparingly.',
                'ሁሉም ፈቃዶች ያሉት የቴክኒክ መድረክ ባለቤት (ያለ ሱፐር አስተዳዳሪ ማለፊያ)። በጥንቃቄ ብቻ ይመደብ።'),
            'City Admin' => self::role(self::SCOPE_GLOBAL, $cityAdmin,
                'City-wide operational administration of structure, employees, ID cards, workflows, services and reports. Does not manage security settings, roles, API credentials or provider-portal sign-in.',
                'የከተማ አቀፍ የሥራ አስተዳደር፡ መዋቅር፣ ሠራተኞች፣ መታወቂያዎች፣ የሥራ ሂደቶች፣ አገልግሎቶች እና ሪፖርቶች። የደህንነት ቅንብሮችን፣ ሚናዎችን፣ የAPI ምስክርነቶችን ወይም የአቅራቢ ፖርታል መግቢያን አያስተዳድርም።'),
            'Public Service Bureau Admin' => self::role(self::SCOPE_GLOBAL, $cityAdmin,
                'Civil Service Bureau administration with the same city-wide operational rights as City Admin.',
                'እንደ ከተማ አስተዳዳሪ ተመሳሳይ የከተማ አቀፍ የሥራ መብት ያለው የሲቪል ሰርቪስ ቢሮ አስተዳደር።'),
            'Organizational Admin' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'reports.view',
                ...self::STRUCTURE_READ,
                'hierarchy-versions.viewAny', 'hierarchy-versions.view', 'organization-edges.view',
                'position-establishments.viewAny', 'position-establishments.view',
                'grade-levels.viewAny', 'grade-levels.view',
                ...self::CHANGE_REQUESTER,
                'employees.viewAny', 'employees.view', 'employees.manage',
                'employees.import.view', 'employees.import.upload', 'employees.import.confirm',
                'id-cards.viewAny', 'id-cards.view', 'id-cards.submitRequest', 'id-cards.previewSvg', 'cards.view',
                'transfers.viewAny', 'transfers.view', 'transfers.applications.view', 'transfers.applications.screen',
                'transfers.release.approve', 'transfers.receiving.approve',
                'vacancy-announcements.viewAny', 'vacancy-announcements.view',
                'vacancy-applications.viewAny', 'vacancy-applications.view',
                'users.viewAny', 'users.view', 'users.create', 'users.update', 'users.deactivate', 'users.archive', 'users.restore',
                'users.assignRoles', 'users.assignOrganizationScopes',
                'user-organization-scopes.viewAny', 'user-organization-scopes.create', 'user-organization-scopes.update', 'user-organization-scopes.delete',
                'service_feedback.view', 'service_feedback.review', 'service_feedback.hide', 'service_feedback.export', 'service_feedback.settings.manage',
                ...DailyActivityRoles::ORGANIZATIONAL_ADMIN_PERMISSIONS,
                ...PerformanceRoles::ORGANIZATIONAL_ADMIN_PERMISSIONS,
            ], 'Manages authorized HR operations within the assigned organization scope and submits controlled structure/position change requests. Does not have global administration or unrestricted master-structure modification rights.',
                'በተመደበው የተቋም ወሰን ውስጥ የተፈቀዱ የሰው ሀብት ሥራዎችን ያስተዳድራል፣ የመዋቅር/የሥራ መደብ ለውጥ ጥያቄዎችን ያቀርባል። ሁሉን አቀፍ አስተዳደር ወይም ያልተገደበ የዋና መዋቅር ማሻሻያ መብት የለውም።'),
            'HR Officer' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'reports.view',
                'organizations.viewAny', 'organizations.view', 'organization-units.viewAny', 'organization-units.view',
                'positions.viewAny', 'positions.view', 'grade-levels.viewAny', 'grade-levels.view',
                'occupations.viewAny', 'occupations.view', 'occupations.create', 'occupations.update',
                'isic-activities.viewAny', 'isic-activities.view',
                'employees.viewAny', 'employees.view', 'employees.manage', 'employees.viewPii',
                'employees.import.view', 'employees.import.upload', 'employees.import.confirm',
                'id-cards.viewAny', 'id-cards.view', 'id-cards.submitRequest', 'id-cards.verifyRequest', 'id-cards.previewSvg', 'cards.view',
                'entitlements.view', 'entitlements.viewAny', 'service-types.viewAny', 'service-types.view',
                'entitlement-rules.viewAny', 'entitlement-rules.view',
                'transfers.viewAny', 'transfers.view', 'transfers.create', 'transfers.update', 'transfers.submit',
                'transfers.applications.view',
                ...DailyActivityRoles::HR_OVERSIGHT_PERMISSIONS,
                ...PerformanceRoles::HR_PERMISSIONS,
            ], 'Keeps employee records, imports, card requests and transfer paperwork within scope. The only default role that sees employee personal identifiers (employees.viewPii).',
                'በወሰኑ ውስጥ የሠራተኛ መዝገቦችን፣ ማስገባቶችን፣ የመታወቂያ ጥያቄዎችን እና የዝውውር ሰነዶችን ይይዛል። የሠራተኞችን የግል መለያ የሚያይ ብቸኛው ነባሪ ሚና።'),

            // ── Structure change workflow (requester ≠ reviewer ≠ implementer) ─
            'Structure Change Requester' => self::role(self::SCOPE_SCOPED, ['dashboard.view', ...self::STRUCTURE_READ, ...self::CHANGE_REQUESTER],
                'Raises organization-unit and position change requests. Requesting never grants the right to edit the structure.',
                'የተቋም ክፍል እና የሥራ መደብ ለውጥ ጥያቄዎችን ያቀርባል። መጠየቅ መዋቅሩን የማሻሻል መብት አይሰጥም።'),
            'Structure Change Reviewer' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'organizations.viewAny', 'organizations.view', 'organization-units.viewAny', 'organization-units.view',
                'positions.viewAny', 'positions.view', 'hierarchy-versions.viewAny', 'hierarchy-versions.view',
                'organizational-change-requests.view', 'organizational-change-requests.review',
                'organizational-change-requests.request_correction', 'organizational-change-requests.approve',
                'organizational-change-requests.reject',
            ], 'Reviews, returns, approves or rejects structure change requests. Cannot implement changes or approve their own requests.',
                'የመዋቅር ለውጥ ጥያቄዎችን ይገመግማል፣ ይመልሳል፣ ያጸድቃል ወይም ውድቅ ያደርጋል። ለውጦችን መተግበር ወይም የራሱን ጥያቄ ማጽደቅ አይችልም።'),
            'Structure Implementation Officer' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'organizations.viewAny', 'organizations.view',
                'organization-units.viewAny', 'organization-units.view', 'organization-units.create', 'organization-units.update',
                'organization-units.archive', 'organization-units.restore', 'organization-units.manageHierarchy',
                'positions.viewAny', 'positions.view', 'positions.create', 'positions.update', 'positions.move',
                'positions.archive', 'positions.restore',
                'position-establishments.viewAny', 'position-establishments.view',
                'hierarchy-versions.viewAny', 'hierarchy-versions.view', 'hierarchy-versions.create', 'hierarchy-versions.update',
                'hierarchy-versions.manageTree', 'organization-edges.view', 'organization-edges.create', 'organization-edges.update',
                'organizational-change-requests.view', 'organizational-change-requests.view_approved',
                'organizational-change-requests.assign_implementation', 'organizational-change-requests.implement',
                'organizational-change-requests.complete',
            ], 'The establishment/structure team: applies approved change requests to units, positions and draft hierarchy versions. Cannot approve requests or publish hierarchy versions.',
                'የመዋቅር ቡድን፡ የጸደቁ የለውጥ ጥያቄዎችን በክፍሎች፣ በሥራ መደቦች እና በረቂቅ የተዋረድ ስሪቶች ላይ ይተገብራል። ጥያቄዎችን ማጽደቅ ወይም የተዋረድ ስሪቶችን ማሳተም አይችልም።'),

            // ── ID cards and verification ───────────────────────────────────
            'ID Card Officer' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'cards.view', 'cards.manage', 'service-types.viewAny', 'service-types.view',
                'id-cards.viewAny', 'id-cards.view', 'id-cards.create', 'id-cards.update', 'id-cards.submitRequest',
                'id-cards.verifyRequest', 'id-cards.createPrintBatch', 'id-cards.print', 'id-cards.reportLost',
                'id-cards.reportDamaged', 'id-cards.exportPng', 'id-cards.previewSvg', 'card-verifications.viewAny',
            ], 'Prepares, verifies and prints ID cards and records loss or damage. Approval, issuing and revocation belong to the ID Card Approver.',
                'መታወቂያዎችን ያዘጋጃል፣ ያረጋግጣል፣ ያትማል፤ መጥፋትን ወይም ጉዳትን ይመዘግባል። ማጽደቅ፣ መስጠት እና መሰረዝ የመታወቂያ አጽዳቂው ነው።'),
            'ID Card Approver' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'cards.view', 'id-cards.viewAny', 'id-cards.view', 'id-cards.previewSvg',
                'id-cards.approveRequest', 'id-cards.rejectRequest', 'id-cards.issue', 'id-cards.activate',
                'id-cards.replace', 'id-cards.revoke', 'card-verifications.viewAny',
            ], 'Approves or rejects card requests and issues, activates, replaces or revokes cards. Does not prepare or print them.',
                'የመታወቂያ ጥያቄዎችን ያጸድቃል ወይም ውድቅ ያደርጋል፤ መታወቂያዎችን ይሰጣል፣ ያነቃል፣ ይተካል ወይም ይሰርዛል። አያዘጋጅም አያትምም።'),
            'NFC Provisioning Officer' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'nfc_credentials.view', 'nfc_credentials.provision', 'nfc_credentials.activate',
                'nfc_credentials.suspend', 'nfc_credentials.revoke', 'nfc_credentials.replace', 'nfc_terminals.view', 'nfc_logs.view',
            ], 'Provisions NFC credentials and manages their lifecycle. Terminal verification itself uses API applications, not this role.',
                'የNFC ምስክርነቶችን ያዘጋጃል እና የሕይወት ዑደታቸውን ያስተዳድራል። የተርሚናል ማረጋገጫ በAPI መተግበሪያዎች ይሠራል።'),

            // ── Security, audit, settings, integrations ──────────────────────
            'Security Settings Manager' => self::role(self::SCOPE_GLOBAL, [
                'dashboard.view', 'audit.view', 'audit-logs.viewAny',
                'roles.viewAny', 'roles.view', 'roles.create', 'roles.update', 'roles.delete', 'roles.assignPermissions',
                'permissions.viewAny', 'permissions.view',
                'users.viewAny', 'users.view', 'users.assignRoles', 'users.resetPassword', 'users.deactivate',
                'user-organization-scopes.viewAny',
                'system-settings.view', 'system-settings.manageSecurity', 'system-settings.manageEmail',
                'system-settings.manageSms', 'system-settings.manageTelegram', 'system-settings.testNotificationChannels',
            ], 'Security administration: roles and their permissions, security and MFA policy, notification-channel credentials, audit review. Cannot alter protected roles unless Super Admin.',
                'የደህንነት አስተዳደር፡ ሚናዎች እና ፈቃዶቻቸው፣ የደህንነት እና የMFA ፖሊሲ፣ የማሳወቂያ ቻናል ምስክርነቶች፣ የኦዲት ግምገማ።'),
            'System Settings Admin' => self::role(self::SCOPE_GLOBAL, [
                'dashboard.view', 'system-settings.view', 'system-settings.update', 'system-settings.manageGeneral',
                'system-settings.manageLocalization', 'system-settings.manageNotifications', 'system-settings.manageAppearance',
                'system-settings.manageUi', 'system-settings.manageIdCards', 'system-settings.uploadAssets', 'system-settings.clearCache',
                'id_card_templates.view', 'id_card_templates.create', 'id_card_templates.update', 'id_card_templates.delete',
                'id_card_templates.set_default',
            ], 'Operational settings: general, localization, notifications, appearance, ID card settings and templates. No security settings, credentials, roles or API access.',
                'የሥራ ቅንብሮች፡ አጠቃላይ፣ ቋንቋ፣ ማሳወቂያ፣ ገጽታ፣ የመታወቂያ ቅንብሮች እና አብነቶች። የደህንነት ቅንብሮች፣ ምስክርነቶች፣ ሚናዎች ወይም የAPI መዳረሻ የለውም።'),
            'API Manager' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'api_management.view', 'api_management.create', 'api_management.update',
                'api_management.delete', 'api_management.tokens.create', 'api_management.tokens.revoke',
                'api_management.logs.view', 'api_management.docs.view', 'api_management.endpoints.view',
                'api_management.endpoints.sync', 'api_management.endpoints.update',
            ], 'Manages external API applications, tokens, endpoints and logs. A token is shown once at issue and never again.',
                'የውጭ API መተግበሪያዎችን፣ ቶከኖችን፣ መዳረሻዎችን እና ምዝግቦችን ያስተዳድራል። ቶከን አንድ ጊዜ ብቻ ይታያል።'),
            'Auditor' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'audit.view', 'audit-logs.viewAny', 'reports.view',
                'occupations.viewAny', 'occupations.view', 'positions.viewAny', 'positions.view',
                'transfers.viewAny', 'transfers.view', 'card-verifications.viewAny',
                'service-types.viewAny', 'entitlement-rules.viewAny',
                'hierarchy-versions.viewAny', 'hierarchy-versions.view', 'organization-edges.view',
                'code-rules.viewAny', 'code-rules.view', 'code-rules.preview',
            ], 'Read-only review of audit logs, history and approved records. Creates, changes and deletes nothing.',
                'የኦዲት ምዝግቦችን፣ ታሪክን እና የጸደቁ መዝገቦችን በንባብ ብቻ ይገመግማል። ምንም አይፈጥርም፣ አይቀይርም፣ አይሰርዝም።'),
            'Report Viewer' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'reports.view', 'daily_activities.view_reports', 'performance_reports.view',
            ], 'Views non-sensitive reports within scope. No exports.',
                'በወሰኑ ውስጥ ስሱ ያልሆኑ ሪፖርቶችን ይመለከታል። ወደ ውጭ መላክ የለም።'),
            'Public Site Manager' => self::role(self::SCOPE_GLOBAL, PermissionCatalog::matching(...self::PUBLIC_SITE_MODULES),
                'Edits the public website content. Cannot change ID verification, OTP, rate limits, employee data or authentication policy.',
                'የሕዝብ ድረ-ገጽ ይዘትን ያስተካክላል። የመታወቂያ ማረጋገጫን፣ OTPን፣ የፍጥነት ገደቦችን፣ የሠራተኛ መረጃን ወይም የማረጋገጫ ፖሊሲን መቀየር አይችልም።'),

            // ── Employee self-service, teams and performance ─────────────────
            DailyActivityRoles::EMPLOYEE_ROLE => self::role(self::SCOPE_SCOPED, [...DailyActivityRoles::EMPLOYEE_PERMISSIONS, ...PerformanceRoles::EMPLOYEE_PERMISSIONS],
                'Self-service for the signed-in employee only: own daily activity, own performance agreement, reviews and appeals. My Portal pages are tied to the linked employee record, not to extra permissions.',
                'ለገባው ሠራተኛ ብቻ የራስ አገልግሎት፡ የራሱ ዕለታዊ እንቅስቃሴ፣ የአፈጻጸም ስምምነት፣ ግምገማዎች እና ይግባኞች።'),
            DailyActivityRoles::REVIEWER_ROLE => self::role(self::SCOPE_SCOPED, DailyActivityRoles::REVIEWER_PERMISSIONS,
                'Reviews the daily activity of the team named in the user\'s reviewer assignment.',
                'በተመደበበት ቡድን ውስጥ ያሉ ሠራተኞችን ዕለታዊ እንቅስቃሴ ይገመግማል።'),
            PerformanceRoles::MANAGER_ROLE => self::role(self::SCOPE_SCOPED, PerformanceRoles::MANAGER_PERMISSIONS,
                'Unit manager / supervisor for EPMS: agreements, actuals, check-ins and reviews of the covered team only.',
                'የክፍል ኃላፊ፡ ለሚመራው ቡድን ብቻ ስምምነቶች፣ ክንውኖች፣ ውይይቶች እና ግምገማዎች።'),
            PerformanceRoles::OFFICER_ROLE => self::role(self::SCOPE_SCOPED, PerformanceRoles::OFFICER_PERMISSIONS,
                'Runs the performance cycle in scope: strategic goals, KPIs, targets, unit and position plans, agreements and reports.',
                'በወሰኑ ውስጥ የአፈጻጸም ዑደቱን ያካሂዳል፡ ስትራቴጂያዊ ግቦች፣ KPIዎች፣ ዒላማዎች፣ ዕቅዶች፣ ስምምነቶች እና ሪፖርቶች።'),
            PerformanceRoles::REVIEWER_ROLE => self::role(self::SCOPE_SCOPED, PerformanceRoles::REVIEWER_PERMISSIONS,
                'Reviews and approves performance plans and strategic goals and verifies reported actuals. Does not calibrate.',
                'የአፈጻጸም ዕቅዶችን እና ስትራቴጂያዊ ግቦችን ይገመግማል፣ ያጸድቃል፤ ክንውኖችን ያረጋግጣል። ካሊብሬሽን አያደርግም።'),
            PerformanceRoles::CALIBRATOR_ROLE => self::role(self::SCOPE_SCOPED, PerformanceRoles::CALIBRATOR_PERMISSIONS,
                'Calibration panel member for the assigned scope. No HR record or plan editing rights.',
                'ለተመደበው ወሰን የካሊብሬሽን ፓነል አባል። የሰው ሀብት መዝገብ ወይም ዕቅድ የማሻሻል መብት የለውም።'),
            PerformanceRoles::APPEAL_COMMITTEE_ROLE => self::role(self::SCOPE_SCOPED, PerformanceRoles::APPEAL_COMMITTEE_PERMISSIONS,
                'Performance appeal committee (chairperson, writer or member, set by committee membership): reviews and decides assigned appeals only.',
                'የአፈጻጸም ይግባኝ ኮሚቴ (ሰብሳቢ፣ ጸሐፊ ወይም አባል በኮሚቴ አባልነት)፡ የተመደቡ ይግባኞችን ብቻ ይገመግማል፣ ይወስናል።'),

            // ── Grievances and the Administrative Tribunal ───────────────────
            'Grievance Officer' => self::role(self::SCOPE_SCOPED, ['dashboard.view', 'grievances.view', 'grievances.manage'],
                'Grievance office: intake, requirement checks, committee assignment and grievance settings.',
                'የቅሬታ ጽ/ቤት፡ መቀበል፣ የመስፈርት ማረጋገጫ፣ የኮሚቴ ምደባ እና የቅሬታ ቅንብሮች።'),
            'Grievance Committee Member' => self::role(self::SCOPE_SCOPED, ['dashboard.view', 'grievances.committee'],
                'Grievance committee member or writer: reviews grievances assigned to the committee. Committee membership is required.',
                'የቅሬታ ኮሚቴ አባል ወይም ጸሐፊ፡ ለኮሚቴው የተመደቡ ቅሬታዎችን ይገመግማል። የኮሚቴ አባልነት ያስፈልጋል።'),
            'Grievance Committee Chairperson' => self::role(self::SCOPE_SCOPED, ['dashboard.view', 'grievances.committee', 'grievances.chairperson'],
                'Chairs a grievance committee: coordinates review and records the recommendation. Chairperson membership is required.',
                'የቅሬታ ኮሚቴን ይመራል፡ ግምገማውን ያስተባብራል እና ምክረ ሐሳቡን ይመዘግባል። የሰብሳቢነት አባልነት ያስፈልጋል።'),
            'Administrative Tribunal Officer' => self::role(self::SCOPE_SCOPED, ['dashboard.view', 'grievances.tribunal'],
                'Handles grievances escalated to the Administrative Tribunal.',
                'ወደ አስተዳደር ፍርድ ቤት የተላለፉ ቅሬታዎችን ያስተናግዳል።'),

            // ── Services and providers (admin side; provider portals use their own roles) ─
            'Cafeteria Admin' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view',
                'cafeteria-provider-users.viewAny', 'cafeteria-provider-users.view', 'cafeteria-provider-users.create',
                'cafeteria-provider-users.update', 'cafeteria-provider-users.resetPassword', 'cafeteria-provider-users.suspend',
                'cafeteria-provider-users.activate', 'cafeteria-provider-users.delete', 'cafeteria-provider-users.restore',
                'cafeteria_providers.viewAny', 'cafeteria_providers.view', 'cafeteria_providers.viewAll', 'cafeteria_providers.create',
                'cafeteria_providers.update', 'cafeteria_providers.assignInstitution', 'cafeteria_providers.archive', 'cafeteria_providers.restore',
                'cafeteria_settings.view', 'cafeteria_settings.update', 'cafeteria_day_rules.view', 'cafeteria_day_rules.update',
                'cafeteria_subsidy_rules.view', 'cafeteria_subsidy_rules.create', 'cafeteria_subsidy_rules.update', 'cafeteria_subsidy_rules.archive',
                'cafeteria_special_days.view', 'cafeteria_special_days.create', 'cafeteria_special_days.update',
                'cafeteria_special_days.archive', 'cafeteria_special_days.restore',
                'public_holidays.viewAny', 'public_holidays.view', 'public_holidays.create', 'public_holidays.update', 'public_holidays.archive',
                'cafeteria_transactions.view', 'cafeteria_transactions.reverse',
                'cafeteria_reports.view', 'cafeteria_reports.generate', 'cafeteria_reports.export', 'cafeteria_ledger.view',
                'cafeteria_employee_exclusions.view', 'cafeteria_employee_exclusions.create', 'cafeteria_employee_exclusions.update',
                'cafeteria_employee_exclusions.end', 'cafeteria_employee_exclusions.archive',
                'cafeteria_networks.view', 'cafeteria_networks.manage',
                'cafeteria_access.view', 'cafeteria_access.manage', 'cafeteria_access.approve',
                'cafeteria_assignments.view', 'cafeteria_assignments.create', 'cafeteria_assignments.update',
                'cafeteria_assignments.end', 'cafeteria_assignments.approve',
                'cafeteria_policies.view', 'cafeteria_policies.create', 'cafeteria_policies.update_draft',
                'cafeteria_policies.submit', 'cafeteria_policies.review', 'cafeteria_policies.approve',
                'cafeteria_policies.activate', 'cafeteria_policies.end',
                'cafeteria_transactions.export', 'cafeteria_settlements.view', 'cafeteria_settlements.manage',
            ], 'Runs the cafeteria subsidy program: providers and their portal accounts, subsidy rules, holidays, transactions and reports.',
                'የካፌቴሪያ ድጎማ ፕሮግራምን ያካሂዳል፡ አቅራቢዎች እና የፖርታል መለያዎቻቸው፣ የድጎማ ደንቦች፣ በዓላት፣ ግብይቶች እና ሪፖርቶች።'),
            'Cafeteria Operator' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'cafeteria_providers.viewAny', 'cafeteria_providers.view', 'cafeteria_settings.view',
                'cafeteria_day_rules.view', 'cafeteria_subsidy_rules.view', 'cafeteria_special_days.view',
                'cafeteria_transactions.view', 'cafeteria_transactions.scan', 'cafeteria_reports.view', 'cafeteria_ledger.view',
                'cafeteria_employee_exclusions.view',
            ], 'Scans cards and views transactions and reports for the assigned cafeteria providers. No settings, user or provider management.',
                'ለተመደቡ የካፌቴሪያ አቅራቢዎች መታወቂያ ይቃኛል፣ ግብይቶችን እና ሪፖርቶችን ይመለከታል። የቅንብር፣ የተጠቃሚ ወይም የአቅራቢ አስተዳደር የለውም።'),
            'Transport Admin' => self::role(self::SCOPE_SCOPED, PermissionCatalog::matching(
                'transport-providers', 'transport-routes', 'transport-vehicles', 'transport-drivers', 'transport-passes',
                'transport-transactions', 'transport-scan', 'transport-reports', 'transport-settings',
            ), 'Runs the transport service: providers, routes, vehicles, drivers, passes, transactions, reports and settings.',
                'የትራንስፖርት አገልግሎትን ያካሂዳል፡ አቅራቢዎች፣ መስመሮች፣ ተሽከርካሪዎች፣ አሽከርካሪዎች፣ ፓሶች፣ ግብይቶች፣ ሪፖርቶች እና ቅንብሮች።'),
            'Transport Operator' => self::role(self::SCOPE_SCOPED, [
                'transport-scan.create', 'transport-transactions.viewAny', 'transport-transactions.view',
                'transport-passes.viewAny', 'transport-passes.view',
            ], 'Scans cards at boarding and views passes and trips. No management rights.',
                'በሚሳፈሩበት ጊዜ መታወቂያ ይቃኛል፣ ፓሶችን እና ጉዞዎችን ይመለከታል። የአስተዳደር መብት የለውም።'),
            'Service Provider Manager' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'service-providers.viewAny', 'service-providers.view', 'service-providers.create',
                'service-providers.update', 'service-providers.delete',
            ], 'Registers external service providers and manages their provider-portal accounts. Provider staff sign in to their own portal, never to admin pages.',
                'የውጭ አገልግሎት አቅራቢዎችን ይመዘግባል እና የአቅራቢ ፖርታል መለያዎቻቸውን ያስተዳድራል።'),
            'Service Provider User' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'transactions.manage', 'service-transactions.viewAny', 'providers.viewAny',
                'service-types.viewAny', 'service-types.view',
            ], 'Legacy staff role for recording service transactions at a provider.',
                'በአቅራቢ የአገልግሎት ግብይቶችን ለመመዝገብ የቆየ የሠራተኛ ሚና።'),
            'Settlement Officer' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'transactions.view', 'service-transactions.viewAny', 'providers.viewAny', 'reports.view',
            ], 'Reviews provider transactions for settlement. Read-only.',
                'ለክፍያ ማስተካከያ የአቅራቢ ግብይቶችን ይገመግማል። በንባብ ብቻ።'),
            'Cafeteria Provider' => self::role(self::SCOPE_SCOPED, [
                'cafeteria-portal.login', 'cafeteria-portal.viewDashboard', 'cafeteria-portal.scan',
                'cafeteria-portal.viewTransactions', 'cafeteria-portal.viewLedger', 'cafeteria-portal.manageMenus',
                'cafeteria-portal.manageOrders', 'cafeteria-portal.viewReports', 'cafeteria-portal.updateProfile',
                'cafeteria-portal.exportTransactions', 'provider-cafeteria-transactions.export', 'provider-cafeteria-payment-claims.export',
            ], 'Legacy: staff account that opens the cafeteria provider portal. New provider staff use provider-portal accounts (owner, manager, operator).',
                'የቆየ፡ የካፌቴሪያ አቅራቢ ፖርታልን የሚከፍት የሠራተኛ መለያ። አዲስ የአቅራቢ ሠራተኞች የአቅራቢ ፖርታል መለያ ይጠቀማሉ።'),
        ];

        if ($validate) {
            foreach ($roles as $role => $definition) {
                foreach ($definition['permissions'] as $name) {
                    if (! PermissionCatalog::has($name)) {
                        throw new InvalidArgumentException("Role '{$role}' references unknown permission '{$name}'.");
                    }
                }
            }
        }

        return $roles;
    }

    /**
     * @param  list<string>  $permissions
     * @return array{description_en: string, description_am: string, scope: string, permissions: list<string>}
     */
    private static function role(string $scope, array $permissions, string $descriptionEn, string $descriptionAm): array
    {
        return ['description_en' => $descriptionEn, 'description_am' => $descriptionAm, 'scope' => $scope, 'permissions' => array_values($permissions)];
    }
}
