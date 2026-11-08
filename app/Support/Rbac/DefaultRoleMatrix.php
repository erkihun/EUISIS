<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Support\Intelligence\IntelligenceRoles;
use InvalidArgumentException;
use Modules\Communication\Contracts\Data\CommunicationRoles;
use Modules\DailyWork\Contracts\Data\DailyActivityRoles;
use Modules\Discipline\Contracts\Data\DisciplineRoles;
use Modules\FieldWork\Contracts\Data\FieldWorkRoles;
use Modules\Grievance\Contracts\Data\GrievanceRoles;
use Modules\Performance\Contracts\Data\PerformanceRoles;
use Modules\ServiceMonitoring\Contracts\Data\ServiceMonitoringRoles;

/**
 * Default system roles and their permissions (docs/rbac-architecture.md, ADR 0026).
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

    private const CITY_ADMIN_WITHHELD_MODULES = ['backups', 'api_management', 'integrations', 'cafeteria-portal', 'provider-cafeteria-transactions', 'provider-cafeteria-payment-claims'];

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

    /**
     * Granted by no default role but the administrators, on purpose (ADR 0026):
     * irreversible deletion, impersonation, sensitive user fields, the city-wide
     * publication of a hierarchy version, and `approve_own`, which nobody holds.
     * Legacy aliases kept for old databases are listed in LEGACY_ALIASES instead.
     *
     * @var list<string>
     */
    public const ADMIN_ONLY = [
        'users.delete', 'users.viewSensitive', 'recycle-bin.forceDelete', 'cafeteria-portal.impersonate',
        'code-rules.delete', 'hierarchy-versions.publish', 'hierarchy-versions.archive',
        'organizational-change-requests.approve_own',
    ];

    /**
     * Legacy permission names that newer, finer permissions replaced. They stay
     * in the catalog for databases that still carry them and are granted to no
     * default role (the modules map them to the new sets themselves).
     *
     * @var list<string>
     */
    public const LEGACY_ALIASES = [
        'organizations.manage', 'entitlements.manage',
        'grievances.view', 'grievances.manage', 'grievances.committee', 'grievances.chairperson', 'grievances.manager',
    ];

    /** What the signed-in employee may do with their own transfer and vacancy applications. */
    private const EMPLOYEE_SELF_SERVICE = [
        'transfers.applications.create', 'vacancy-applications.submit', 'vacancy-applications.withdraw',
    ];

    /** Opening the Transfer module and reading what is in scope; every transfer role starts here. */
    private const TRANSFER_READ = [
        'transfers.view', 'transfers.viewAny', 'transfers.applications.view', 'transfers.announcements.view', 'transfers.reports.view',
    ];

    /** Reading public submissions in scope: Feedback and Tip-offs, Complaints, and both targets. */
    private const FEEDBACK_READ = [
        'service_feedback.view', 'service_feedback.tip_offs.view', 'service_feedback.complaints.view',
        'service_feedback.view_organization_target', 'service_feedback.view_employee_target',
    ];

    /** The Situation Room categories (docs/situation-room-access-control.md). */
    private const SITUATION_ROOM_DOMAINS = [
        'situation_room.workforce.view', 'situation_room.public_voice.view', 'situation_room.operations.view',
        'situation_room.performance.view', 'situation_room.cafeteria.view', 'situation_room.transport.view', 'situation_room.cases.view',
    ];

    /** Intelligence business areas (docs/intelligence-architecture.md D-3: business ≠ technical). */
    private const INTELLIGENCE_BUSINESS = [
        'intelligence.view', 'intelligence.executive.view', 'intelligence.workforce.view', 'intelligence.service.view',
        'intelligence.public-voice.view', 'intelligence.performance.view', 'intelligence.forecasting.view',
        'intelligence.data-quality.view', 'intelligence.knowledge.view',
        'intelligence.recommendations.view', 'intelligence.recommendations.review',
    ];

    /** Intelligence technical areas: platform health, incidents, diagnostics and runbooks. */
    private const INTELLIGENCE_TECHNICAL = [
        'intelligence.view', 'intelligence.system-health.view', 'intelligence.incidents.view', 'intelligence.incidents.manage',
        'intelligence.diagnostics.view', 'intelligence.application-health.view', 'intelligence.database-health.view',
        'intelligence.queue-health.view', 'intelligence.integration-health.view', 'intelligence.deployment-analysis.view',
        'intelligence.runbooks.view', 'intelligence.runbooks.execute', 'intelligence.data-quality.view',
        'intelligence.recommendations.view', 'intelligence.recommendations.review',
    ];

    /** Backup health, history and alerts; recovery itself is a four-eyes workflow (docs/backup-recovery-architecture.md). */
    private const BACKUP_READ = ['backups.view_status', 'backups.view_history', 'backups.view_logs'];

    /** Reading a court file: cases, documents, hearings, letters, decisions, appeals, parties, inspections. */
    private const COURT_READ = [
        'court.cases.view', 'court.file.view', 'court.hearing.view', 'court.letters.view', 'court.letters.templet.view',
        'court.decisions.view', 'court.decision.view', 'court.appeals.view', 'court.applicants.view', 'court.view.inspections',
    ];

    /** What a judge does on the bench: bench notes, hearings, draft decisions, messages to the parties. */
    private const COURT_JUDGE = [
        'dashboard.view', ...self::COURT_READ, 'court.judge',
        'court.bench-notes.view', 'court.bench-notes.create', 'court.bench-notes.update', 'court.bench-notes.delete', 'court.bench-notes.manage',
        'court.decision.create', 'court.decision.update', 'court.hearing.create', 'court.hearing.update',
        'court.notes.manage', 'court.message.create',
    ];

    /** Handling public submissions assigned to the user: review, outcome, closure. */
    private const FEEDBACK_HANDLER = [
        'dashboard.view', ...self::FEEDBACK_READ,
        'service_feedback.review', 'service_feedback.tip_offs.review', 'service_feedback.complaints.review', 'service_feedback.resolve', 'service_feedback.close',
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
        $cityAdmin = array_values(array_diff($all, self::CITY_ADMIN_WITHHELD, GrievanceRoles::CITY_ADMIN_WITHHELD, DisciplineRoles::CITY_ADMIN_WITHHELD, FieldWorkRoles::CITY_ADMIN_WITHHELD, ServiceMonitoringRoles::CITY_ADMIN_WITHHELD, CommunicationRoles::CITY_ADMIN_WITHHELD, IntelligenceRoles::CITY_ADMIN_WITHHELD, PermissionCatalog::matching(...self::CITY_ADMIN_WITHHELD_MODULES)));

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
                'employees.viewAny', 'employees.view', 'employees.manage', 'employees.delete',
                'employees.import.view', 'employees.import.upload', 'employees.import.confirm',
                'id-cards.viewAny', 'id-cards.view', 'id-cards.submitRequest', 'id-cards.previewSvg', 'cards.view',
                'transfers.viewAny', 'transfers.view', 'transfers.applications.view', 'transfers.applications.screen',
                'transfers.release.approve', 'transfers.receiving.approve',
                'vacancy-announcements.viewAny', 'vacancy-announcements.view',
                'vacancy-applications.viewAny', 'vacancy-applications.view',
                'users.viewAny', 'users.view', 'users.create', 'users.update', 'users.deactivate', 'users.archive', 'users.restore',
                'users.assignRoles', 'users.assignOrganizationScopes', 'users.updateProfilePhoto',
                'user-organization-scopes.viewAny', 'user-organization-scopes.create', 'user-organization-scopes.update',
                'user-organization-scopes.delete', 'user-organization-scopes.restore',
                'structural-offices.view', 'structural-offices.manage', 'structural-offices.assignUsers', 'structural-offices.manageEmployees',
                'service_feedback.view', 'service_feedback.review', 'service_feedback.tip_offs.view', 'service_feedback.tip_offs.review', 'service_feedback.hide', 'service_feedback.export', 'service_feedback.settings.manage',
                'service_feedback.view_employee_target', 'service_feedback.view_organization_target',
                ...ReportingLineRoles::ORGANIZATIONAL_ADMIN_PERMISSIONS,
                ...DailyActivityRoles::ORGANIZATIONAL_ADMIN_PERMISSIONS,
                ...FieldWorkRoles::ORGANIZATIONAL_ADMIN_PERMISSIONS,
                ...PerformanceRoles::ORGANIZATIONAL_ADMIN_PERMISSIONS,
                ...PerformanceRoles::ASSESSMENT_INSTITUTION_ADMIN_PERMISSIONS,
                ...PerformanceRoles::ASSESSMENT_EXECUTION_ADMIN_PERMISSIONS,
                ...PerformanceRoles::ASSESSMENT_EVALUATOR_PERMISSIONS,
                ...CommunicationRoles::ORGANIZATIONAL_ADMIN_PERMISSIONS,
            ], 'Manages authorized HR operations within the assigned organization scope and submits controlled structure/position change requests. Does not have global administration or unrestricted master-structure modification rights.',
                'በተመደበው የተቋም ወሰን ውስጥ የተፈቀዱ የሰው ሀብት ሥራዎችን ያስተዳድራል፣ የመዋቅር/የሥራ መደብ ለውጥ ጥያቄዎችን ያቀርባል። ሁሉን አቀፍ አስተዳደር ወይም ያልተገደበ የዋና መዋቅር ማሻሻያ መብት የለውም።'),
            'HR Officer' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'reports.view',
                'organizations.viewAny', 'organizations.view', 'organization-units.viewAny', 'organization-units.view',
                'positions.viewAny', 'positions.view', 'grade-levels.viewAny', 'grade-levels.view',
                'occupations.viewAny', 'occupations.view', 'occupations.create', 'occupations.update',
                'isic-activities.viewAny', 'isic-activities.view',
                'employees.viewAny', 'employees.view', 'employees.manage', 'employees.delete', 'employees.viewPii',
                'employees.import.view', 'employees.import.upload', 'employees.import.confirm',
                'id-cards.viewAny', 'id-cards.view', 'id-cards.submitRequest', 'id-cards.verifyRequest', 'id-cards.previewSvg', 'cards.view',
                'entitlements.view', 'entitlements.viewAny', 'service-types.viewAny', 'service-types.view',
                'entitlement-rules.viewAny', 'entitlement-rules.view',
                'transfers.viewAny', 'transfers.view', 'transfers.create', 'transfers.update', 'transfers.submit',
                'transfers.applications.view', 'structural-offices.view',
                ...ReportingLineRoles::HR_PERMISSIONS,
                ...DailyActivityRoles::HR_OVERSIGHT_PERMISSIONS,
                ...FieldWorkRoles::HR_OVERSIGHT_PERMISSIONS,
                ...PerformanceRoles::HR_PERMISSIONS,
                ...PerformanceRoles::ASSESSMENT_HR_PERMISSIONS,
                ...PerformanceRoles::ASSESSMENT_EXECUTION_HR_PERMISSIONS,
                ...PerformanceRoles::ASSESSMENT_EVALUATOR_PERMISSIONS,
                ...CommunicationRoles::HR_PERMISSIONS,
            ], 'Keeps employee records, imports, card requests and transfer paperwork within scope. The only default role that sees employee personal identifiers (employees.viewPii).',
                'በወሰኑ ውስጥ የሠራተኛ መዝገቦችን፣ ማስገባቶችን፣ የመታወቂያ ጥያቄዎችን እና የዝውውር ሰነዶችን ይይዛል። የሠራተኞችን የግል መለያ የሚያይ ብቸኛው ነባሪ ሚና።'),

            // ── Employee transfers and vacancies (officer ≠ approver ≠ final approver ≠ implementer) ─
            // Scope decides which side of a transfer the user acts for: release needs the
            // releasing institution in scope, receiving the receiving one (EmployeeTransferPolicy).
            'Transfer Vacancy Officer' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', ...self::TRANSFER_READ,
                'transfers.announcements.create', 'transfers.announcements.update', 'transfers.announcements.publish', 'transfers.announcements.close',
                'transfers.applications.screen', 'transfers.create', 'transfers.update', 'transfers.submit', 'transfers.cancel',
                'employees.viewAny', 'employees.view', 'positions.viewAny', 'positions.view',
            ], 'Runs transfer vacancies for the institutions in scope: announces, publishes and closes them, screens applications, and drafts, submits or cancels direct transfer requests. Approves nothing.',
                'በወሰኑ ውስጥ ላሉ ተቋማት የዝውውር ክፍት የሥራ መደቦችን ያካሂዳል፡ ያውጃል፣ ያትማል፣ ይዘጋል፣ ማመልከቻዎችን ያጣራል፣ ቀጥተኛ የዝውውር ጥያቄዎችን ያረቅቃል፣ ያቀርባል ወይም ይሰርዛል። ምንም አያጸድቅም።'),
            'Transfer Approver' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', ...self::TRANSFER_READ, 'employees.viewAny', 'employees.view',
                'transfers.release.approve', 'transfers.receiving.approve',
                'transfers.confirmCurrentOrganization', 'transfers.confirmReceivingOrganization',
                'transfers.approve', 'transfers.reject',
            ], 'Institution head or delegate: approves or rejects the release of an employee by the institution in scope and the acceptance of an employee into it. Never gives the final city-level approval.',
                'የተቋም ኃላፊ ወይም ወኪል፡ በወሰኑ ውስጥ ያለው ተቋም ሠራተኛን መልቀቁን እና ሠራተኛን መቀበሉን ያጸድቃል ወይም ውድቅ ያደርጋል። የመጨረሻውን የከተማ ደረጃ ማጽደቅ በፍጹም አይሰጥም።'),
            'Transfer Final Approver' => self::role(self::SCOPE_GLOBAL, [
                'dashboard.view', ...self::TRANSFER_READ, 'employees.viewAny', 'employees.view',
                'transfers.final.approve', 'transfers.override.approve',
            ], 'City or bureau level: gives the final approval of transfers that both institutions have approved and decides exceptional policy overrides. Does not execute the transfer.',
                'የከተማ ወይም የቢሮ ደረጃ፡ ሁለቱም ተቋማት ያጸደቋቸውን ዝውውሮች በመጨረሻ ያጸድቃል፤ ልዩ የፖሊሲ ማለፊያ ጥያቄዎችን ይወስናል። ዝውውሩን አይፈጽምም።'),
            'Transfer Administrator' => self::role(self::SCOPE_GLOBAL, [
                'dashboard.view', ...self::TRANSFER_READ, 'employees.viewAny', 'employees.view',
                'transfers.complete', 'transfers.settings.manage', 'transfers.reports.export', 'transfers.export',
            ], 'Executes finally approved transfers (closes the old assignment, opens the new one), keeps the transfer policy settings and exports transfer reports. Approves nothing.',
                'በመጨረሻ የጸደቁ ዝውውሮችን ይፈጽማል (የቀድሞውን ምደባ ይዘጋል፣ አዲሱን ይከፍታል)፣ የዝውውር ፖሊሲ ቅንብሮችን ይይዛል፣ የዝውውር ሪፖርቶችን ወደ ውጭ ይልካል። ምንም አያጸድቅም።'),
            'Vacancy Officer' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'positions.viewAny', 'positions.view',
                'vacancy-announcements.viewAny', 'vacancy-announcements.view', 'vacancy-announcements.create',
                'vacancy-announcements.update', 'vacancy-announcements.close', 'vacancy-announcements.delete',
                'vacancy-applications.viewAny', 'vacancy-applications.view', 'vacancy-applications.screen',
            ], 'Drafts, edits and closes vacancy announcements for the institutions in scope and screens the applications. Publication and selection belong to the Vacancy Selection Approver.',
                'በወሰኑ ውስጥ ላሉ ተቋማት የክፍት የሥራ መደብ ማስታወቂያዎችን ያረቅቃል፣ ያስተካክላል፣ ይዘጋል፤ ማመልከቻዎችን ያጣራል። ማሳተም እና መምረጥ የክፍት የሥራ መደብ ምርጫ አጽዳቂው ነው።'),
            'Vacancy Selection Approver' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'positions.viewAny', 'positions.view', 'transfers.view', 'transfers.viewAny',
                'vacancy-announcements.viewAny', 'vacancy-announcements.view', 'vacancy-announcements.publish',
                'vacancy-applications.viewAny', 'vacancy-applications.view', 'vacancy-applications.select',
                'vacancy-applications.reject', 'vacancy-applications.initiateTransfer',
            ], 'Publishes screened vacancy announcements, selects or rejects screened applicants and starts the transfer of a selected employee. Cannot draft announcements or screen.',
                'የተጣሩ የክፍት የሥራ መደብ ማስታወቂያዎችን ያትማል፣ የተጣሩ አመልካቾችን ይመርጣል ወይም ውድቅ ያደርጋል፣ የተመረጠውን ሠራተኛ ዝውውር ይጀምራል። ማስታወቂያ ማርቀቅ ወይም ማጣራት አይችልም።'),

            // ── Organization Design & Workforce Planning (designer ≠ approver) ─
            // Records enforce maker ≠ checker themselves; the roles keep the two
            // seats apart by default. City-level catalogs have their own role.
            'Organization Designer' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', ...self::STRUCTURE_READ,
                'institution-mandates.viewAny', 'institution-mandates.view', 'institution-mandates.create', 'institution-mandates.update',
                'organization-functions.viewAny', 'organization-functions.manage',
                'service-catalog.viewAny', 'service-catalog.view', 'service-catalog.create', 'service-catalog.update', 'classifications.viewAny',
                'processes.viewAny', 'processes.view', 'processes.create', 'processes.update', 'work_standards.manage', 'competency-catalog.viewAny',
                'organization-design.viewAny', 'organization-design.view', 'organization-design.create', 'organization-design.update',
                'workforce-planning.viewAny', 'workforce-planning.view', 'workforce-planning.create', 'workforce-planning.update', 'methodologies.viewAny',
                'position-profiles.viewAny', 'position-profiles.manage', 'job-descriptions.viewAny', 'job-descriptions.manage',
                'organization-design-reports.view', 'data-mapping.view',
                'legal-sources.viewAny', 'legal-sources.view', 'legal-sources.create', 'legal-sources.update',
                'legacy-baselines.viewAny', 'legacy-baselines.view', 'legacy-baselines.create', 'legacy-baselines.update',
                'design-imports.viewAny', 'design-imports.create', 'design-suggestions.view', 'design-suggestions.apply',
            ], 'Drafts the design of organizations in scope: mandates, functions, services, processes and standards, design studies, manpower plans, position profiles and job descriptions. Never approves or publishes.',
                'በወሰኑ ውስጥ ያሉ ተቋማትን ንድፍ ያረቅቃል፡ ሥልጣኖች፣ ተግባራት፣ አገልግሎቶች፣ ሂደቶችና ስታንዳርዶች፣ የንድፍ ጥናቶች፣ የሰው ኃይል ዕቅዶች፣ የሥራ መደብ መገለጫዎችና የሥራ መግለጫዎች። በጭራሽ አያጸድቅም ወይም አያትምም።'),
            'Organization Design Approver' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', ...self::STRUCTURE_READ,
                'institution-mandates.viewAny', 'institution-mandates.view', 'institution-mandates.approve',
                'organization-functions.viewAny', 'service-catalog.viewAny', 'service-catalog.view', 'service-catalog.approve', 'classifications.viewAny',
                'processes.viewAny', 'processes.view', 'processes.approve', 'work_standards.approve', 'competency-catalog.viewAny',
                'organization-design.viewAny', 'organization-design.view', 'organization-design.review', 'organization-design.approve',
                'workforce-planning.viewAny', 'workforce-planning.view', 'workforce-planning.review', 'workforce-planning.approve', 'workforce-planning.implement', 'methodologies.viewAny',
                'position-profiles.viewAny', 'position-profiles.approve', 'job-descriptions.viewAny', 'job-descriptions.approve', 'job-descriptions.publish',
                'organization-design-reports.view', 'organization-design-reports.export', 'data-mapping.view',
                'legal-sources.viewAny', 'legal-sources.view',
                'legacy-baselines.viewAny', 'legacy-baselines.view', 'legacy-baselines.review', 'legacy-baselines.approve', 'legacy-baselines.publish',
                'design-imports.viewAny', 'design-imports.commit', 'design-suggestions.view',
            ], 'Reviews, approves and publishes the design drafted by others in scope, implements approved manpower plans, and exports the design reports. Cannot draft.',
                'በወሰኑ ውስጥ በሌሎች የተረቀቀውን ንድፍ ይገመግማል፣ ያጸድቃል እና ያትማል፣ የጸደቁ የሰው ኃይል ዕቅዶችን ይተገብራል፣ የንድፍ ሪፖርቶችን ወደ ውጭ ይልካል። ማርቀቅ አይችልም።'),
            'Design Catalog Manager' => self::role(self::SCOPE_GLOBAL, [
                'dashboard.view', 'occupations.viewAny', 'occupations.view',
                'classifications.viewAny', 'classifications.manage', 'professions.manage', 'competency-catalog.viewAny', 'competency-catalog.manage',
                'responsibility-types.manage', 'methodologies.viewAny', 'methodologies.manage', 'methodologies.approve', 'data-mapping.view',
                'legal-sources.viewAny', 'legal-sources.view', 'legal-sources.manageCatalog',
            ], 'City-level master data of the design: service and ISIC/CPC/ISCO classifications, professions, the competency catalog, responsibility types and planning methodologies. Official datasets only; nothing organization-specific.',
                'የንድፉ የከተማ ደረጃ ዋና መረጃ፡ የአገልግሎትና የISIC/CPC/ISCO ምደባዎች፣ ሙያዎች፣ የብቃት ካታሎግ፣ የኃላፊነት ዓይነቶችና የዕቅድ ዘዴዎች። ይፋዊ መረጃዎች ብቻ፤ የተቋም-ተኮር ነገር የለም።'),

            // ── Structure change workflow (requester ≠ reviewer ≠ implementer) ─
            'Structure Change Requester' => self::role(self::SCOPE_SCOPED, ['dashboard.view', ...self::STRUCTURE_READ, ...self::CHANGE_REQUESTER],
                'Raises organization-unit and position change requests. Requesting never grants the right to edit the structure.',
                'የሥራ ክፍል እና የሥራ መደብ ለውጥ ጥያቄዎችን ያቀርባል። መጠየቅ መዋቅሩን የማሻሻል መብት አይሰጥም።'),
            'Structure Change Reviewer' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'organizations.viewAny', 'organizations.view', 'organization-units.viewAny', 'organization-units.view',
                'positions.viewAny', 'positions.view', 'hierarchy-versions.viewAny', 'hierarchy-versions.view',
                'position-establishments.viewAny', 'position-establishments.view', 'position-establishments.approve',
                'organizational-change-requests.view', 'organizational-change-requests.review',
                'organizational-change-requests.request_correction', 'organizational-change-requests.approve',
                'organizational-change-requests.reject',
            ], 'Reviews, returns, approves or rejects structure change requests and approves position establishment records. Cannot implement changes or approve their own requests.',
                'የመዋቅር ለውጥ ጥያቄዎችን ይገመግማል፣ ይመልሳል፣ ያጸድቃል ወይም ውድቅ ያደርጋል፤ የሥራ መደብ ምሥረታ መዝገቦችን ያጸድቃል። ለውጦችን መተግበር ወይም የራሱን ጥያቄ ማጽደቅ አይችልም።'),
            'Structure Implementation Officer' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'core.access', 'organizations.viewAny', 'organizations.view',
                'organization-units.viewAny', 'organization-units.view', 'organization-units.create', 'organization-units.update',
                'organization-units.archive', 'organization-units.restore', 'organization-units.manageHierarchy',
                'organization-units.delete', 'organization-units.viewDeleted', 'organization-units.export',
                'positions.viewAny', 'positions.view', 'positions.create', 'positions.update', 'positions.move',
                'positions.archive', 'positions.restore', 'positions.delete', 'positions.viewDeleted', 'positions.export',
                'position-establishments.viewAny', 'position-establishments.view', 'position-establishments.create',
                'position-establishments.update', 'position-establishments.archive', 'position-establishments.restore',
                'hierarchy-versions.viewAny', 'hierarchy-versions.view', 'hierarchy-versions.create', 'hierarchy-versions.update',
                'hierarchy-versions.manageTree', 'organization-edges.view', 'organization-edges.create', 'organization-edges.update',
                'organization-edges.remove',
                'organizational-change-requests.view', 'organizational-change-requests.view_approved',
                'organizational-change-requests.assign_implementation', 'organizational-change-requests.implement',
                'organizational-change-requests.complete',
            ], 'The establishment/structure team: applies approved change requests to units, positions, position establishments and draft hierarchy versions. Cannot approve requests or publish hierarchy versions.',
                'የመዋቅር ቡድን፡ የጸደቁ የለውጥ ጥያቄዎችን በክፍሎች፣ በሥራ መደቦች፣ በሥራ መደብ ምሥረታዎች እና በረቂቅ የተዋረድ ስሪቶች ላይ ይተገብራል። ጥያቄዎችን ማጽደቅ ወይም የተዋረድ ስሪቶችን ማሳተም አይችልም።'),
            'Organization Registrar' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'core.access', ...self::STRUCTURE_READ,
                'organizations.create', 'organizations.update', 'organizations.delete', 'organizations.import',
                'institution-offices.viewAny', 'institution-offices.view', 'institution-offices.create', 'institution-offices.update',
                'institution-offices.delete', 'institution-offices.restore', 'institution-offices.move', 'institution-offices.viewTree',
                'institution-offices.export',
                'relationships.viewAny', 'relationships.view', 'relationships.create', 'relationships.update',
                'relationships.delete', 'relationships.restore',
                'structural-offices.view', 'hierarchy-versions.viewAny', 'hierarchy-versions.view', 'organization-edges.view',
                'organization-units.export', 'positions.export',
            ], 'Registers and maintains the institutions in scope, their offices and their structural and functional relationships, and imports organization lists. Units and positions follow the change-request workflow; hierarchy versions are published by City Admin.',
                'በወሰኑ ውስጥ ያሉ ተቋማትን፣ ጽሕፈት ቤቶቻቸውን እና መዋቅራዊና ተግባራዊ ግንኙነቶቻቸውን ይመዘግባል፣ ይይዛል፤ የተቋማት ዝርዝር ያስገባል። የሥራ ክፍሎች እና የሥራ መደቦች በለውጥ ጥያቄ ሂደት ይሠራሉ፤ የተዋረድ ስሪቶች በከተማ አስተዳዳሪ ይታተማሉ።'),
            'Structure Catalog Manager' => self::role(self::SCOPE_GLOBAL, [
                'dashboard.view', 'core.access',
                ...PermissionCatalog::matching('organization-types', 'organization-unit-types', 'grade-levels', 'occupations', 'isic-activities'),
            ], 'City-wide classification master data of the structure: organization types, unit types, grade levels, the occupation catalog and ISIC activities. Nothing organization-specific.',
                'የመዋቅሩ የከተማ አቀፍ የምደባ ዋና መረጃ፡ የተቋም ዓይነቶች፣ የሥራ ክፍል ዓይነቶች፣ የደረጃ መዓርጎች፣ የሙያ ካታሎግ እና የISIC ተግባራት። የተቋም-ተኮር ነገር የለም።'),
            'Code Rule Administrator' => self::role(self::SCOPE_GLOBAL, [
                'dashboard.view', ...array_values(array_diff(PermissionCatalog::matching('code-rules'), ['code-rules.delete'])),
            ], 'Defines the code generation rules (employee, unit, position and card numbers), their sequences and manual overrides, previews and triggers generation. Rules are archived, never deleted.',
                'የኮድ አመንጪ ደንቦችን (የሠራተኛ፣ የሥራ ክፍል፣ የሥራ መደብ እና የመታወቂያ ቁጥሮች)፣ ተከታታይ ቁጥሮቻቸውን እና በእጅ ማለፊያዎችን ይወስናል፤ ቅድመ-እይታ ያደርጋል፣ ማመንጨት ይጀምራል። ደንቦች ይቀመጣሉ እንጂ አይሰረዙም።'),
            'Functional Supervisor' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', ...PermissionCatalog::matching('functional-reporting'),
            ], 'Technical or professional supervision through configured functional reporting lines: sees, approves and exports only what those lines expose. Grants no structural authority.',
                'በተዋቀሩ የተግባር ሪፖርት መስመሮች በኩል ቴክኒካዊ ወይም ሙያዊ ቁጥጥር፡ እነዚያ መስመሮች የሚያሳዩትን ብቻ ያያል፣ ያጸድቃል፣ ወደ ውጭ ይልካል። መዋቅራዊ ሥልጣን አይሰጥም።'),

            // ── ID cards and verification ───────────────────────────────────
            'ID Card Officer' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'cards.view', 'cards.manage', 'service-types.viewAny', 'service-types.view',
                'id-cards.viewAny', 'id-cards.view', 'id-cards.create', 'id-cards.update', 'id-cards.submitRequest',
                'id-cards.verifyRequest', 'id-cards.createPrintBatch', 'id-cards.print', 'id-cards.reportLost',
                'id-cards.reportDamaged', 'id-cards.exportPng', 'id-cards.previewSvg', 'id-cards.export', 'id-cards.verify',
                'card-verifications.viewAny',
            ], 'Prepares, verifies and prints ID cards and records loss or damage. Approval, issuing and revocation belong to the ID Card Approver.',
                'መታወቂያዎችን ያዘጋጃል፣ ያረጋግጣል፣ ያትማል፤ መጥፋትን ወይም ጉዳትን ይመዘግባል። ማጽደቅ፣ መስጠት እና መሰረዝ የመታወቂያ አጽዳቂው ነው።'),
            'ID Card Approver' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'cards.view', 'id-cards.viewAny', 'id-cards.view', 'id-cards.previewSvg',
                'id-cards.approveRequest', 'id-cards.rejectRequest', 'id-cards.issue', 'id-cards.activate',
                'id-cards.replace', 'id-cards.revoke', 'id-cards.archive', 'id-cards.verify', 'card-verifications.viewAny',
            ], 'Approves or rejects card requests and issues, activates, replaces or revokes cards. Does not prepare or print them.',
                'የመታወቂያ ጥያቄዎችን ያጸድቃል ወይም ውድቅ ያደርጋል፤ መታወቂያዎችን ይሰጣል፣ ያነቃል፣ ይተካል ወይም ይሰርዛል። አያዘጋጅም አያትምም።'),
            'NFC Provisioning Officer' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'nfc_credentials.view', 'nfc_credentials.provision', 'nfc_credentials.activate',
                'nfc_credentials.suspend', 'nfc_credentials.revoke', 'nfc_credentials.replace', 'nfc_terminals.view', 'nfc_logs.view',
            ], 'Provisions NFC credentials and manages their lifecycle. Terminal verification itself uses API applications, not this role.',
                'የNFC ምስክርነቶችን ያዘጋጃል እና የሕይወት ዑደታቸውን ያስተዳድራል። የተርሚናል ማረጋገጫ በAPI መተግበሪያዎች ይሠራል።'),
            'NFC Terminal Administrator' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'nfc_terminals.view', 'nfc_terminals.manage', 'nfc_terminals.create', 'nfc_terminals.update',
                'nfc_terminals.delete', 'nfc_logs.view',
            ], 'Registers, updates and retires the NFC service terminals of the institutions in scope and reads their logs. Credentials are provisioned by the NFC Provisioning Officer, never by this role.',
                'በወሰኑ ውስጥ ላሉ ተቋማት የNFC አገልግሎት ተርሚናሎችን ይመዘግባል፣ ያዘምናል፣ ያስወግዳል፤ ምዝግቦቻቸውን ያነባል። ምስክርነቶች በNFC አዘጋጅ ኃላፊ እንጂ በዚህ ሚና አይዘጋጁም።'),

            // ── Security, audit, settings, integrations ──────────────────────
            'Security Settings Manager' => self::role(self::SCOPE_GLOBAL, [
                'dashboard.view', 'audit.view', 'audit-logs.viewAny',
                'roles.viewAny', 'roles.view', 'roles.create', 'roles.update', 'roles.delete', 'roles.assignPermissions',
                'permissions.viewAny', 'permissions.view', 'permissions.create', 'permissions.update', 'permissions.delete',
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
                'system-settings.manageSituationRoom',
                'id_card_templates.view', 'id_card_templates.create', 'id_card_templates.update', 'id_card_templates.delete',
                'id_card_templates.set_default',
            ], 'Operational settings: general, localization, notifications, appearance, ID card settings and templates. No security settings, credentials, roles or API access.',
                'የሥራ ቅንብሮች፡ አጠቃላይ፣ ቋንቋ፣ ማሳወቂያ፣ ገጽታ፣ የመታወቂያ ቅንብሮች እና አብነቶች። የደህንነት ቅንብሮች፣ ምስክርነቶች፣ ሚናዎች ወይም የAPI መዳረሻ የለውም።'),
            'API Manager' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'api_management.view', 'api_management.create', 'api_management.update',
                'api_management.delete', 'api_management.tokens.create', 'api_management.tokens.revoke',
                'api_management.logs.view', 'api_management.docs.view', 'api_management.endpoints.view',
                'api_management.endpoints.sync', 'api_management.endpoints.update',
                'integrations.view', 'integrations.manage', 'integrations.deliveries.view', 'integrations.replay', 'integrations.payload.view',
            ], 'Manages external API applications, tokens, endpoints and logs. A token is shown once at issue and never again.',
                'የውጭ API መተግበሪያዎችን፣ ቶከኖችን፣ መዳረሻዎችን እና ምዝግቦችን ያስተዳድራል። ቶከን አንድ ጊዜ ብቻ ይታያል።'),
            'Auditor' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'dashboard.reports', 'audit.view', 'audit-logs.viewAny', 'reports.view',
                'recycle-bin.view', 'recycle-bin.viewDetails',
                'occupations.viewAny', 'occupations.view', 'positions.viewAny', 'positions.view',
                'transfers.viewAny', 'transfers.view', 'card-verifications.viewAny',
                'service-types.viewAny', 'entitlement-rules.viewAny',
                'hierarchy-versions.viewAny', 'hierarchy-versions.view', 'organization-edges.view',
                'code-rules.viewAny', 'code-rules.view', 'code-rules.preview',
                'reception.view', 'reception.reports.view', 'reception.audit',
                ...ReportingLineRoles::HR_PERMISSIONS,
                ...GrievanceRoles::AUDITOR_PERMISSIONS,
            ], 'Read-only review of audit logs, history and approved records. Creates, changes and deletes nothing.',
                'የኦዲት ምዝግቦችን፣ ታሪክን እና የጸደቁ መዝገቦችን በንባብ ብቻ ይገመግማል። ምንም አይፈጥርም፣ አይቀይርም፣ አይሰርዝም።'),
            'Report Viewer' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'dashboard.reports', 'reports.view', 'daily_activities.view_reports', 'performance_reports.view',
                ...GrievanceRoles::REPORT_VIEWER_PERMISSIONS,
                ...DisciplineRoles::REPORT_VIEWER_PERMISSIONS,
            ], 'Views non-sensitive reports within scope. No exports.',
                'በወሰኑ ውስጥ ስሱ ያልሆኑ ሪፖርቶችን ይመለከታል። ወደ ውጭ መላክ የለም።'),

            // ── Executive dashboards and intelligence (aggregates by default; drill-down is a separate role) ─
            'Situation Room Viewer' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'situation_room.view', 'situation_room.wall_mode', ...self::SITUATION_ROOM_DOMAINS,
            ], 'Institution head or planning officer: the Situation Room overview, trends, every category and the wall display for the institutions in scope. Aggregate figures only; no exports, no named employees, no submission text.',
                'የተቋም ኃላፊ ወይም የዕቅድ ባለሙያ፡ በወሰኑ ውስጥ ላሉ ተቋማት የሁኔታ ክፍል አጠቃላይ እይታ፣ አዝማሚያዎች፣ ሁሉም ምድቦች እና የግድግዳ ማሳያ። አጠቃላይ አሃዞች ብቻ፤ ወደ ውጭ መላክ፣ የተሰየሙ ሠራተኞች ወይም የማመልከቻ ጽሑፍ የለም።'),
            'Situation Room Executive' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'situation_room.view', 'situation_room.wall_mode', ...self::SITUATION_ROOM_DOMAINS,
                'situation_room.export', 'situation_room.view_detail', 'situation_room.view_employee_targeted', 'situation_room.manage_actions',
                'service_feedback.view', 'service_feedback.tip_offs.view', 'service_feedback.view_employee_target',
            ], 'Executive with drill-down: everything the viewer sees plus executive report exports, audited employee-level tables and submission text inside one institution, and the Action Register.',
                'ዝርዝር የሚያይ ሥራ አስፈጻሚ፡ ተመልካቹ የሚያየው ሁሉ፣ የሥራ አስፈጻሚ ሪፖርት ወደ ውጭ መላክ፣ በአንድ ተቋም ውስጥ የሚመዘገቡ የሠራተኛ ደረጃ ሰንጠረዦችና የማመልከቻ ጽሑፍ፣ እና የእርምጃ መዝገብ።'),
            'Intelligence Analyst' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', ...self::INTELLIGENCE_BUSINESS,
            ], 'Business intelligence within scope: executive risks, workforce, service, public voice and performance analytics, forecasts, data quality, knowledge questions, and the review of recommendations. No technical diagnostics.',
                'በወሰኑ ውስጥ የንግድ ኢንተለጀንስ፡ የሥራ አስፈጻሚ ስጋቶች፣ የሰው ኃይል፣ አገልግሎት፣ የሕዝብ ድምፅ እና የአፈጻጸም ትንታኔዎች፣ ትንበያዎች፣ የመረጃ ጥራት፣ የዕውቀት ጥያቄዎች እና የምክረ ሀሳቦች ግምገማ። የቴክኒክ ምርመራ የለም።'),
            'Public Site Manager' => self::role(self::SCOPE_GLOBAL, PermissionCatalog::matching(...self::PUBLIC_SITE_MODULES),
                'Edits the public website content. Cannot change ID verification, OTP, rate limits, employee data or authentication policy.',
                'የሕዝብ ድረ-ገጽ ይዘትን ያስተካክላል። የመታወቂያ ማረጋገጫን፣ OTPን፣ የፍጥነት ገደቦችን፣ የሠራተኛ መረጃን ወይም የማረጋገጫ ፖሊሲን መቀየር አይችልም።'),
            'Workflow Policy Administrator' => self::role(self::SCOPE_GLOBAL, [
                'dashboard.view', 'system-settings.view', 'system-settings.manageFieldWorkGps',
                'daily_activity_settings.view', 'daily_activity_settings.update',
                'performance_settings.view', 'performance_settings.update',
                'communication.settings.manage', 'field_work.manage_types',
            ], 'Keeps the city-wide policy settings of the workflow modules: daily activity deadlines, performance settings, communication policy, the field work type catalog and GPS policy. Grants no access to the records themselves.',
                'የሥራ ሂደት ሞጁሎችን የከተማ አቀፍ የፖሊሲ ቅንብሮች ይይዛል፡ የዕለታዊ እንቅስቃሴ የጊዜ ገደቦች፣ የአፈጻጸም ቅንብሮች፣ የኮሙኒኬሽን ፖሊሲ፣ የመስክ ሥራ ዓይነት ካታሎግ እና የGPS ፖሊሲ። ለመዝገቦቹ ራሳቸው መዳረሻ አይሰጥም።'),
            'Recycle Bin Manager' => self::role(self::SCOPE_GLOBAL, [
                'dashboard.view', 'recycle-bin.view', 'recycle-bin.viewDetails', 'recycle-bin.restore',
            ], 'Reviews soft-deleted records across modules and restores them. Permanent deletion stays with the administrators.',
                'በሞጁሎች ውስጥ ለጊዜው የተሰረዙ መዝገቦችን ይገመግማል እና ይመልሳል። ዘላቂ መሰረዝ የአስተዳዳሪዎች ሆኖ ይቀራል።'),
            'Help Desk Agent' => self::role(self::SCOPE_GLOBAL, [
                'dashboard.view', 'support-tickets.view-all', 'support-tickets.manage',
            ], 'Works the help desk: opens every support ticket with its safe diagnostic context, changes status, assigns it and adds notes. Every change is audited.',
                'የእገዛ ዴስክን ይሠራል፡ እያንዳንዱን የድጋፍ ትኬት ከደህንነቱ የተጠበቀ የምርመራ አውድ ጋር ይከፍታል፣ ሁኔታ ይቀይራል፣ ይመድባል፣ ማስታወሻ ይጨምራል። እያንዳንዱ ለውጥ ይመዘገባል።'),
            'Platform Operations Engineer' => self::role(self::SCOPE_GLOBAL, [
                'dashboard.view', ...self::INTELLIGENCE_TECHNICAL, ...self::BACKUP_READ,
                'integrations.view', 'integrations.deliveries.view',
            ], 'Technical operations: platform health, incidents, diagnostics, deployment analysis, audited runbooks, backup health and integration deliveries. Sees no business analytics and no employee records.',
                'የቴክኒክ ሥራዎች፡ የመድረክ ጤና፣ ክስተቶች፣ ምርመራዎች፣ የማሰማራት ትንተና፣ የሚመዘገቡ የሥራ መመሪያዎች፣ የምትኬ ጤና እና የውህደት አቅርቦቶች። የንግድ ትንታኔዎችንም የሠራተኛ መዝገቦችንም አያይም።'),
            'Intelligence Governance Officer' => self::role(self::SCOPE_GLOBAL, [
                'dashboard.view', 'intelligence.view', 'intelligence.governance.view', 'intelligence.governance.manage',
                'intelligence.knowledge.view', 'intelligence.recommendations.view', 'intelligence.data-quality.view',
                'system-settings.view', 'system-settings.manageIntelligence',
            ], 'Governs the AI features: approves knowledge documents, records evaluations, retires prompt versions and sets feature switches, limits and thresholds. Provider secrets stay in the environment.',
                'የAI ባህሪያትን ያስተዳድራል፡ የዕውቀት ሰነዶችን ያጸድቃል፣ ግምገማዎችን ይመዘግባል፣ የፕሮምፕት ስሪቶችን ያስወግዳል፣ የባህሪ ማብሪያዎችን፣ ገደቦችን እና መነሻዎችን ያዘጋጃል። የአቅራቢ ምስጢሮች በአካባቢው ይቀራሉ።'),
            // ── Backup and recovery: the operator, the requester, the reviewer and the approver are four people ─
            'Backup Operator' => self::role(self::SCOPE_GLOBAL, [
                'dashboard.view', ...self::BACKUP_READ, 'backups.run', 'backups.verify',
            ], 'Watches backup health, history and alerts, records operator recovery executions and oversees verification. Requests, reviews and approves no recovery.',
                'የምትኬ ጤናን፣ ታሪክን እና ማንቂያዎችን ይከታተላል፣ የኦፕሬተር መልሶ ማግኛ አፈጻጸሞችን ይመዘግባል፣ ማረጋገጫን ይቆጣጠራል። መልሶ ማግኛ አይጠይቅም፣ አይገመግምም፣ አያጸድቅም።'),
            'Recovery Requester' => self::role(self::SCOPE_GLOBAL, [
                'dashboard.view', ...self::BACKUP_READ, 'backups.restore_request',
            ], 'Requests a database recovery with its target and reason. Cannot review or approve a request.',
                'የመረጃ ቋት መልሶ ማግኛን ከዒላማውና ከምክንያቱ ጋር ይጠይቃል። ጥያቄን መገምገም ወይም ማጽደቅ አይችልም።'),
            'Recovery Reviewer' => self::role(self::SCOPE_GLOBAL, [
                'dashboard.view', ...self::BACKUP_READ, 'backups.restore_review',
            ], 'Reviews the evidence of a recovery request made by someone else. Cannot request or approve.',
                'በሌላ ሰው የቀረበ የመልሶ ማግኛ ጥያቄ ማስረጃን ይገመግማል። መጠየቅ ወይም ማጽደቅ አይችልም።'),
            'Recovery Approver' => self::role(self::SCOPE_GLOBAL, [
                'dashboard.view', ...self::BACKUP_READ, 'backups.restore_approve', 'backups.manage_policy',
            ], 'Approves reviewed recovery requests and owns the backup policy. Cannot request or review.',
                'የተገመገሙ የመልሶ ማግኛ ጥያቄዎችን ያጸድቃል፣ የምትኬ ፖሊሲውን ይይዛል። መጠየቅ ወይም መገምገም አይችልም።'),

            // ── Employee self-service, teams and performance ─────────────────
            DailyActivityRoles::EMPLOYEE_ROLE => self::role(self::SCOPE_SCOPED, [...DailyActivityRoles::EMPLOYEE_PERMISSIONS, ...FieldWorkRoles::EMPLOYEE_PERMISSIONS, ...PerformanceRoles::EMPLOYEE_PERMISSIONS, ...GrievanceRoles::EMPLOYEE_PERMISSIONS, ...DisciplineRoles::EMPLOYEE_PERMISSIONS, ...PerformanceRoles::ASSESSMENT_EVALUATOR_PERMISSIONS, ...CommunicationRoles::EMPLOYEE_PERMISSIONS, ...self::EMPLOYEE_SELF_SERVICE],
                'Self-service for the signed-in employee only: own daily activity, own field work requests and GPS check-in/out, own performance agreement, reviews and appeals, own transfer and vacancy applications. My Portal pages are tied to the linked employee record, not to extra permissions.',
                'ለገባው ሠራተኛ ብቻ የራስ አገልግሎት፡ የራሱ ዕለታዊ እንቅስቃሴ፣ የመስክ ሥራ ጥያቄ፣ የአፈጻጸም ስምምነት፣ ግምገማዎች እና ይግባኞች፣ የራሱ የዝውውር እና የክፍት የሥራ መደብ ማመልከቻዎች።'),
            // ── Communication oversight: who asks for investigation access never approves it ─
            CommunicationRoles::MODERATOR_ROLE => self::role(self::SCOPE_SCOPED, CommunicationRoles::MODERATOR_PERMISSIONS,
                'Reviews reported messages and conversations: sees only the reported content and its immediate context, and can remove it. Never browses private conversations.',
                'ሪፖርት የተደረጉ መልዕክቶችንና ውይይቶችን ይገመግማል፦ ሪፖርት የተደረገውን ይዘትና የቅርብ አውዱን ብቻ ያያል፣ ሊያስወግደውም ይችላል። የግል ውይይቶችን አያስስም።'),
            CommunicationRoles::INVESTIGATOR_ROLE => self::role(self::SCOPE_GLOBAL, CommunicationRoles::INVESTIGATOR_PERMISSIONS,
                'Requests time-limited, audited access to a named conversation or employee for a legal or disciplinary investigation. Cannot approve requests.',
                'ለሕጋዊ ወይም ለዲሲፕሊን ምርመራ ለተወሰነ ውይይት ወይም ሠራተኛ በጊዜ የተገደበና የሚመዘገብ ፈቃድ ይጠይቃል። ጥያቄዎችን ማጽደቅ አይችልም።'),
            CommunicationRoles::PRIVACY_OFFICER_ROLE => self::role(self::SCOPE_GLOBAL, CommunicationRoles::PRIVACY_OFFICER_PERMISSIONS,
                'Approves, rejects and revokes investigation access requests made by others, and reads the communication audit trail. Reads no messages itself.',
                'በሌሎች የቀረቡ የምርመራ ፈቃድ ጥያቄዎችን ያጸድቃል፣ ውድቅ ያደርጋል፣ ይሽራል፤ የኮሙኒኬሽን የኦዲት መዝገብን ያነባል። ራሱ መልዕክት አያነብም።'),
            // ── Reporting lines (ADR 0025): the role says what a supervisor may see and lend; the
            // resolved reporting relationship says over whom. Assigned by an administrator, never seeded to users.
            ReportingLineRoles::SUPERVISOR_ROLE => self::role(self::SCOPE_SCOPED, ReportingLineRoles::SUPERVISOR_PERMISSIONS,
                'Sees the employees the organization structure places under them (direct and indirect reports) and may lend named supervisor actions to a colleague for a period. Grants nothing over anyone else.',
                'የተቋሙ መዋቅር በሥራቸው የሚያስቀምጣቸውን ሠራተኞች (ቀጥተኛና ተዘዋዋሪ ሪፖርት አድራጊዎች) ያያል፤ የተሰየሙ የኃላፊ ተግባራትን ለተወሰነ ጊዜ ለባልደረባ ሊያውል ይችላል። በሌላ ማንም ላይ ምንም አይሰጥም።'),
            DailyActivityRoles::REVIEWER_ROLE => self::role(self::SCOPE_SCOPED, [...DailyActivityRoles::REVIEWER_PERMISSIONS, ...FieldWorkRoles::SUPERVISOR_PERMISSIONS],
                'Reviews the daily activity, and decides the field work requests, of the team named in the user\'s reviewer assignment.',
                'በተመደበበት ቡድን ውስጥ ያሉ ሠራተኞችን ዕለታዊ እንቅስቃሴ ይገመግማል፤ የመስክ ሥራ ጥያቄዎቻቸውን ይወስናል።'),
            'Field Work Location Auditor' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', ...FieldWorkRoles::HR_OVERSIGHT_PERMISSIONS, 'field_work.location.view_precise',
            ], 'Privileged: sees the exact coordinates, accuracy and distance of field work check-ins and check-outs in scope, for disputes and audits. Every precise-location view is audited.',
                'ልዩ መብት፡ ለክርክሮች እና ኦዲቶች በወሰኑ ውስጥ የመስክ ሥራ መግቢያና መውጫ ትክክለኛ መጋጠሚያዎችን፣ ትክክለኛነትን እና ርቀትን ያያል። እያንዳንዱ ትክክለኛ ቦታ እይታ ይመዘገባል።'),
            PerformanceRoles::MANAGER_ROLE => self::role(self::SCOPE_SCOPED, [...PerformanceRoles::MANAGER_PERMISSIONS, ...FieldWorkRoles::SUPERVISOR_PERMISSIONS, ...PerformanceRoles::ASSESSMENT_MANAGER_PERMISSIONS, ...PerformanceRoles::ASSESSMENT_EVALUATOR_PERMISSIONS],
                'Unit manager / supervisor for EPMS: agreements, actuals, check-ins and reviews of the covered team only.',
                'የክፍል ኃላፊ፡ ለሚመራው ቡድን ብቻ ስምምነቶች፣ ክንውኖች፣ ውይይቶች እና ግምገማዎች።'),
            PerformanceRoles::OFFICER_ROLE => self::role(self::SCOPE_SCOPED, [...PerformanceRoles::OFFICER_PERMISSIONS, ...PerformanceRoles::ASSESSMENT_OFFICER_PERMISSIONS],
                'Runs the performance cycle in scope: strategic goals, KPIs, targets, unit and position plans, agreements and reports.',
                'በወሰኑ ውስጥ የአፈጻጸም ዑደቱን ያካሂዳል፡ ስትራቴጂያዊ ግቦች፣ KPIዎች፣ ዒላማዎች፣ ዕቅዶች፣ ስምምነቶች እና ሪፖርቶች።'),
            PerformanceRoles::REVIEWER_ROLE => self::role(self::SCOPE_SCOPED, PerformanceRoles::REVIEWER_PERMISSIONS,
                'Reviews and approves performance plans and strategic goals and verifies reported actuals. Does not calibrate.',
                'የአፈጻጸም ዕቅዶችን እና ስትራቴጂያዊ ግቦችን ይገመግማል፣ ያጸድቃል፤ ክንውኖችን ያረጋግጣል። የውጤት ማመጣጠን አያካሂድም።'),
            PerformanceRoles::CALIBRATOR_ROLE => self::role(self::SCOPE_SCOPED, PerformanceRoles::CALIBRATOR_PERMISSIONS,
                'Calibration panel member for the assigned scope. No HR record or plan editing rights.',
                'ለተመደበው ወሰን የውጤት ማመጣጠን ቡድን አባል። የሰው ሀብት መዝገብ ወይም ዕቅድ የማሻሻል መብት የለውም።'),
            PerformanceRoles::ASSESSMENT_OVERSIGHT_ROLE => self::role(self::SCOPE_SCOPED, PerformanceRoles::ASSESSMENT_OVERSIGHT_PERMISSIONS,
                'City assessment oversight: monitors coverage and data quality, approves exclusions, reviews, returns and verifies institutional submissions. Does not finalize or edit scores.',
                'የከተማ የምዘና ክትትል፡ ሽፋንን እና የመረጃ ጥራትን ይከታተላል፣ ማግለሎችን ያጸድቃል፣ የተቋማትን ማጠቃለያ ይገመግማል፣ ይመልሳል፣ ያረጋግጣል። አያጠናቅቅም፣ ውጤት አያስተካክልም።'),
            PerformanceRoles::ASSESSMENT_REPORT_VIEWER_ROLE => self::role(self::SCOPE_SCOPED, PerformanceRoles::ASSESSMENT_REPORT_VIEWER_PERMISSIONS,
                'Aggregate assessment figures and reports only: no employee lists, results or responses.',
                'አጠቃላይ የምዘና አሃዞች እና ሪፖርቶች ብቻ፡ የሠራተኛ ዝርዝር፣ ውጤት ወይም ምላሽ የለም።'),
            PerformanceRoles::APPEAL_COMMITTEE_ROLE => self::role(self::SCOPE_SCOPED, PerformanceRoles::APPEAL_COMMITTEE_PERMISSIONS,
                'Performance appeal committee (chairperson, writer or member, set by committee membership): reviews and decides assigned appeals only.',
                'የአፈጻጸም ይግባኝ ኮሚቴ (ሰብሳቢ፣ ጸሐፊ ወይም አባል በኮሚቴ አባልነት)፡ የተመደቡ ይግባኞችን ብቻ ይገመግማል፣ ይወስናል።'),
            // Oversight verifies a submission (Assessment Oversight Officer); a different seat finalizes it.
            'Assessment Administrator' => self::role(self::SCOPE_GLOBAL, [
                'dashboard.view', 'assessment_oversight.view_dashboard', 'assessment_oversight.view_institutions', 'assessment_oversight.view_unit',
                'assessment_oversight.view_employee_status', 'assessment_oversight.view_results', 'assessment_oversight.view_data_quality',
                'assessment_oversight.manage_cycles', 'assessment_oversight.manage_policies', 'assessment_submissions.finalize',
                'assessment_reports.view', 'assessment_reports.export',
            ], 'City assessment administration: opens and closes assessment cycles, sets participation, eligibility, result bands and unassessed reasons, and finalizes verified institutional submissions. Does not review, return or verify them.',
                'የከተማ የምዘና አስተዳደር፡ የምዘና ዑደቶችን ይከፍታል፣ ይዘጋል፤ ተሳትፎን፣ ብቁነትን፣ የውጤት ደረጃዎችን እና ያልተመዘኑ ምክንያቶችን ያዘጋጃል፤ የተረጋገጡ የተቋም ማጠቃለያዎችን ያጠናቅቃል። አይገመግማቸውም፣ አይመልሳቸውም፣ አያረጋግጣቸውም።'),
            'Assessment Data Auditor' => self::role(self::SCOPE_GLOBAL, [
                'dashboard.view', 'assessment_oversight.view_dashboard', 'assessment_oversight.view_institutions', 'assessment_oversight.view_unit',
                'assessment_oversight.view_employee_status', 'assessment_oversight.view_results', 'assessment_oversight.view_responses',
                'assessment_oversight.view_demographics', 'assessment_oversight.view_data_quality',
                'assessment_results.view', 'assessment_results.view_detailed', 'assessment_reports.view', 'assessment_reports.export',
            ], 'Read-only audit of assessment data down to criterion-level responses and evaluator identity, for disputes and quality checks. Changes nothing.',
                'ለክርክሮች እና የጥራት ፍተሻዎች እስከ መስፈርት ደረጃ ምላሾች እና የገምጋሚ ማንነት ድረስ የምዘና መረጃን በንባብ ብቻ ይመረምራል። ምንም አይቀይርም።'),

            // ── Grievance Management and the Administrative Tribunal ─────────
            // Roles make a user eligible; case access still needs membership,
            // assignment or oversight scope (GrievanceCaseAccessService).
            GrievanceRoles::OFFICER_ROLE => self::role(self::SCOPE_SCOPED, GrievanceRoles::OFFICER_PERMISSIONS,
                'Grievance intake and Team/Directorate case officer: intake review in scope, cases of the user\'s grievance unit, case officers, drafting and letters.',
                'የቅሬታ መቀበያ እና የቡድን/ዳይሬክቶሬት የጉዳይ ኃላፊ፡ በወሰን ውስጥ የመቀበያ ግምገማ፣ የተጠቃሚው የቅሬታ ክፍል ጉዳዮች፣ የጉዳይ ኃላፊዎች፣ ረቂቅ እና ደብዳቤዎች።'),
            GrievanceRoles::COMMITTEE_MEMBER_ROLE => self::role(self::SCOPE_SCOPED, GrievanceRoles::COMMITTEE_MEMBER_PERMISSIONS,
                'Grievance committee member: reviews cases assigned to a committee the user actively serves on. Membership is required.',
                'የቅሬታ ኮሚቴ አባል፡ ተጠቃሚው በንቃት በሚያገለግልበት ኮሚቴ የተመደቡ ጉዳዮችን ይገመግማል። አባልነት ያስፈልጋል።'),
            GrievanceRoles::COMMITTEE_WRITER_ROLE => self::role(self::SCOPE_SCOPED, GrievanceRoles::COMMITTEE_WRITER_PERMISSIONS,
                'Grievance committee writer: member duties plus minutes, draft decisions and draft letters. Writer membership is required.',
                'የቅሬታ ኮሚቴ ጸሐፊ፡ የአባል ሥራ እና ቃለ ጉባኤ፣ የውሳኔ እና የደብዳቤ ረቂቆች። የጸሐፊነት አባልነት ያስፈልጋል።'),
            GrievanceRoles::COMMITTEE_CHAIR_ROLE => self::role(self::SCOPE_SCOPED, GrievanceRoles::COMMITTEE_CHAIR_PERMISSIONS,
                'Chairs a grievance committee: leads review, confirms minutes, decides recusals, submits and finalizes decisions. Chairperson membership is required.',
                'የቅሬታ ኮሚቴን ይመራል፡ ግምገማን ይመራል፣ ቃለ ጉባኤን ያረጋግጣል፣ ራስን ማግለልን ይወስናል፣ ውሳኔዎችን ያቀርባል እና ያጠናቅቃል። የሰብሳቢነት አባልነት ያስፈልጋል።'),
            GrievanceRoles::APPROVER_ROLE => self::role(self::SCOPE_SCOPED, GrievanceRoles::APPROVER_PERMISSIONS,
                'Institution head or superior official: approves, returns or rejects only the decisions routed to the position the user holds (or a delegation).',
                'የተቋም ኃላፊ ወይም የበላይ ባለሥልጣን፡ ተጠቃሚው ለያዘው የሥራ መደብ (ወይም በውክልና) የቀረቡ ውሳኔዎችን ብቻ ያጸድቃል፣ ይመልሳል ወይም ውድቅ ያደርጋል።'),
            GrievanceRoles::ADMINISTRATOR_ROLE => self::role(self::SCOPE_SCOPED, GrievanceRoles::ADMINISTRATOR_PERMISSIONS,
                'Configures grievance committees, routes, SLA policies, approval rules and templates. No access to case content.',
                'የቅሬታ ኮሚቴዎችን፣ መስመሮችን፣ የአገልግሎት ጊዜ ፖሊሲዎችን፣ የማጽደቅ ደንቦችን እና አብነቶችን ያዋቅራል። የጉዳይ ይዘት መዳረሻ የለውም።'),
            GrievanceRoles::REGISTRY_ROLE => self::role(self::SCOPE_SCOPED, GrievanceRoles::REGISTRY_PERMISSIONS,
                'Correspondence registry: custody of the official seal, sealing and dispatch of signed grievance letters in scope.',
                'የደብዳቤ መዝገብ ቤት፡ የይፋዊ ማኅተም ጥበቃ፣ በወሰን ውስጥ የተፈረሙ የቅሬታ ደብዳቤዎችን ማኅተም ማድረግ እና መላክ።'),
            GrievanceRoles::OVERSIGHT_ROLE => self::role(self::SCOPE_SCOPED, GrievanceRoles::OVERSIGHT_PERMISSIONS,
                'Read-only grievance oversight and reports within scope. Highly restricted cases show metadata only.',
                'በወሰን ውስጥ የቅሬታ ቁጥጥር እና ሪፖርቶች በንባብ ብቻ። በጣም የተገደቡ ጉዳዮች መረጃ-ዝርዝር ብቻ ያሳያሉ።'),
            GrievanceRoles::TRIBUNAL_ROLE => self::role(self::SCOPE_SCOPED, GrievanceRoles::TRIBUNAL_PERMISSIONS,
                'Handles grievances routed to the Administrative Tribunal.',
                'ወደ አስተዳደር ፍርድ ቤት የተላለፉ ቅሬታዎችን ያስተናግዳል።'),
            'Grievance Records Custodian' => self::role(self::SCOPE_SCOPED, [
                ...GrievanceRoles::OVERSIGHT_PERMISSIONS, 'grievances.reopen', 'grievances.archive',
            ], 'Records management for closed grievances in scope: archives cases past retention, places and lifts legal holds, and reopens a closed case with a reason where policy allows. Fully audited; takes part in no decision.',
                'በወሰኑ ውስጥ ለተዘጉ ቅሬታዎች የመዝገብ አስተዳደር፡ የማቆያ ጊዜ ያለፈባቸውን ጉዳዮች ያስቀምጣል፣ ሕጋዊ እገዳዎችን ያደርጋል እና ያነሳል፣ ፖሊሲ በሚፈቅድበት የተዘጋ ጉዳይን በምክንያት ይከፍታል። ሙሉ በሙሉ ይመዘገባል፤ በምንም ውሳኔ አይሳተፍም።'),

            // ── Court cases (docs/court-cases.md): registry ≠ bench ≠ presidency ≠ inspection ≠ administration ─
            // Court staff membership and case assignment still decide which file opens; the role only
            // says what the member may do with it. Bench notes are the judges' own.
            'Court Viewer' => self::role(self::SCOPE_SCOPED, ['dashboard.view', ...self::COURT_READ],
                'Reads court files in scope: cases, documents, hearings, letters, decisions, appeals, parties and inspections. Writes nothing and never sees bench notes.',
                'በወሰኑ ውስጥ የፍርድ ቤት መዝገቦችን ያነባል፡ ጉዳዮች፣ ሰነዶች፣ ችሎቶች፣ ደብዳቤዎች፣ ውሳኔዎች፣ ይግባኞች፣ ተከራካሪዎች እና ምርመራዎች። ምንም አይጽፍም፤ የዳኛ ማስታወሻዎችን በፍጹም አያይም።'),
            'Court Registrar' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', ...self::COURT_READ,
                'court.cases.create', 'court.cases.edit', 'court.cases.merge', 'court.cases.review',
                'court.cases.assign.team', 'court.cases.assign.member', 'court.cases.response-replies.manage',
                'court.applicants.manage', 'court.file.create', 'court.file.update',
                'court.hearing.create', 'court.hearing.update', 'court.letters.create', 'court.letters.update',
                'court.appeals.create', 'court.appeals.edit', 'court.inspection-requests.manage',
                'court.message.create', 'court.notes.manage',
            ], 'The registry: registers, reviews and merges cases, keeps parties, files, hearings, letters and appeal records, assigns cases to teams and members and writes to the parties. Decides nothing and approves no letter.',
                'መዝገብ ቤት፡ ጉዳዮችን ይመዘግባል፣ ይገመግማል፣ ያዋህዳል፤ ተከራካሪዎችን፣ ሰነዶችን፣ ችሎቶችን፣ ደብዳቤዎችን እና የይግባኝ መዝገቦችን ይይዛል፤ ጉዳዮችን ለቡድኖችና ለአባላት ይመድባል፤ ለተከራካሪዎች ይጽፋል። ምንም አይወስንም፤ ደብዳቤ አያጸድቅም።'),
            'Court Judge' => self::role(self::SCOPE_SCOPED, self::COURT_JUDGE,
                'A judge on the cases, panels and bench notes they are given: schedules hearings, keeps bench notes, drafts and edits decisions and writes to the parties. Approval of a decision belongs to the Court President.',
                'በተሰጣቸው ጉዳዮች፣ ችሎቶች እና የዳኛ ማስታወሻዎች ላይ ዳኛ፡ ችሎት ይቀጥራል፣ የዳኛ ማስታወሻ ይይዛል፣ ውሳኔዎችን ያረቅቃል፣ ያስተካክላል፣ ለተከራካሪዎች ይጽፋል። ውሳኔን ማጽደቅ የፍርድ ቤቱ ፕሬዚዳንት ነው።'),
            'Court President' => self::role(self::SCOPE_SCOPED, [
                ...self::COURT_JUDGE,
                'court.decision.approve', 'court.decision.view.all', 'court.letters.approve', 'court.appeals.decide',
                'court.cases.assign', 'court.cases.assign.team', 'court.cases.assign.member', 'court.assign.inspections', 'court.teams.manage',
            ], 'Presides over the court: everything a judge does, plus assigning cases, teams and inspections, approving decisions and letters, deciding appeals and viewing every decision.',
                'ፍርድ ቤቱን ይመራል፡ ዳኛ የሚሠራው ሁሉ፣ በተጨማሪም ጉዳዮችን፣ ቡድኖችን እና ምርመራዎችን መመደብ፣ ውሳኔዎችንና ደብዳቤዎችን ማጽደቅ፣ ይግባኞችን መወሰን እና እያንዳንዱን ውሳኔ ማየት።'),
            'Court Inspector' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', 'court.cases.view', 'court.file.view', 'court.view.inspections', 'court.create.inspections',
                'court.case-inspections.manage', 'court.inspection-findings.manage', 'court.inspection-requests.manage', 'court.notes.manage',
            ], 'Carries out the case inspections assigned to them and records findings and requests. Reads only the case and its files; no hearings, decisions or letters.',
                'የተመደቡላቸውን የጉዳይ ምርመራዎች ያካሂዳል፣ ግኝቶችንና ጥያቄዎችን ይመዘግባል። ጉዳዩንና ሰነዶቹን ብቻ ያነባል፤ ችሎት፣ ውሳኔ ወይም ደብዳቤ የለም።'),
            'Court Administrator' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', ...self::COURT_READ, 'reports.export', 'court.reports.export',
                'court.settings.manage', 'court.about.manage', 'court.announcements.manage', 'court.teams.manage', 'court.templates.manage',
                'court.letters.templet.create', 'court.letters.templet.update', 'court.letters.templet.delete', 'court.decision.templet.create',
                'court.cases.types', 'court.applicants.manage', 'court.assign.inspections',
                'court.cases.delete', 'court.file.delete', 'court.hearing.delete', 'court.letters.delete', 'court.decision.delete', 'court.delete.inspections',
            ], 'Administers the court: settings, terms, landing page, announcements, teams, case types, letter and decision templates, exports, and the removal of records entered in error. Sits on no bench and drafts no decision.',
                'ፍርድ ቤቱን ያስተዳድራል፡ ቅንብሮች፣ ውሎች፣ የመግቢያ ገጽ፣ ማስታወቂያዎች፣ ቡድኖች፣ የጉዳይ ዓይነቶች፣ የደብዳቤና የውሳኔ አብነቶች፣ ወደ ውጭ መላክ እና በስህተት የገቡ መዝገቦችን ማስወገድ። በችሎት አይቀመጥም፤ ውሳኔ አያረቅቅም።'),

            // ── Employee Discipline (Regulation 201/2018) ─────────────────────
            // Roles make a user eligible; case work still needs a seat on the
            // case or organization scope (DisciplinaryCaseAccess).
            DisciplineRoles::CASE_OFFICER_ROLE => self::role(self::SCOPE_SCOPED, DisciplineRoles::CASE_OFFICER_PERMISSIONS,
                'HR discipline officer: opens cases, records charges, assigns the committee or investigator, files and serves charges, records decision delivery. Never decides.',
                'የሰው ሀብት የዲሲፕሊን ባለሙያ፡ ጉዳይ ይከፍታል፣ ክስ ይመዘግባል፣ ኮሚቴ ወይም አጣሪ ይመድባል፣ ክስ ያቀርባል፣ መጥሪያ ይልካል፣ የውሳኔ መድረስን ይመዘግባል። አይወስንም።'),
            DisciplineRoles::COMMITTEE_MEMBER_ROLE => self::role(self::SCOPE_SCOPED, DisciplineRoles::COMMITTEE_MEMBER_PERMISSIONS,
                'Disciplinary committee member or appointed investigator: works only the cases the user is seated on.',
                'የዲሲፕሊን ኮሚቴ አባል ወይም የተመደበ አጣሪ፡ የተመደበባቸውን ጉዳዮች ብቻ ይሠራል።'),
            DisciplineRoles::DECISION_AUTHORITY_ROLE => self::role(self::SCOPE_SCOPED, DisciplineRoles::DECISION_AUTHORITY_PERMISSIONS,
                'Head of institution (or delegate): decides on submitted disciplinary recommendations in scope.',
                'የተቋሙ የበላይ ኃላፊ (ወይም ወኪል)፡ በወሰኑ ውስጥ በቀረቡ የዲሲፕሊን የውሳኔ ሀሳቦች ላይ ይወስናል።'),

            // ── Service Delivery Monitoring & Support (docs/service-monitoring.md) ─
            // A role makes a user eligible; the participant's organization must be in
            // scope, and city-level duties need city-wide reach (ServiceMonitoringAccess).
            ServiceMonitoringRoles::REPORTER_ROLE => self::role(self::SCOPE_SCOPED, ServiceMonitoringRoles::REPORTER_PERMISSIONS,
                'Prepares the institution\'s quarterly service-delivery report. Cannot attest or submit it.',
                'የተቋሙን የሩብ ዓመት የአገልግሎት አሰጣጥ ሪፖርት ያዘጋጃል። ሪፖርቱን ማረጋገጥ ወይም ማቅረብ አይችልም።'),
            ServiceMonitoringRoles::HEAD_ROLE => self::role(self::SCOPE_SCOPED, ServiceMonitoringRoles::HEAD_PERMISSIONS,
                'Institution head or delegate: prepares, attests and submits the quarterly report and sees the names in Section I.',
                'የተቋም ኃላፊ ወይም ወኪል፡ የሩብ ዓመት ሪፖርቱን ያዘጋጃል፣ ያረጋግጣል፣ ያቀርባል፤ በክፍል አንድ ያሉ ስሞችን ያያል።'),
            ServiceMonitoringRoles::REVIEWER_ROLE => self::role(self::SCOPE_SCOPED, ServiceMonitoringRoles::REVIEWER_PERMISSIONS,
                'Sub-city reviewer: returns or accepts the reports of the woredas in scope, consolidates and certifies the sub-city, exports its forms and answers clarifications.',
                'የክ/ከተማ ገምጋሚ፡ በወሰኑ ውስጥ ያሉ ወረዳዎችን ሪፖርት ይመልሳል ወይም ይቀበላል፣ የክ/ከተማውን ማጠቃለያ ያረጋግጣል፣ ቅጾቹን ወደ ውጭ ይልካል፣ የማብራሪያ ጥያቄዎችን ይመልሳል።'),
            ServiceMonitoringRoles::COORDINATOR_ROLE => self::role(self::SCOPE_GLOBAL, ServiceMonitoringRoles::COORDINATOR_PERMISSIONS,
                'City coordinator: periods and deadlines, the registry of reporting institutions, extensions, review of sub-city and center reports, locking and reopening periods, exports and settings. Does not verify digital services.',
                'የከተማ አስተባባሪ፡ ጊዜያትና የጊዜ ገደቦች፣ ሪፖርት የሚያቀርቡ ተቋማት መዝገብ፣ የጊዜ ማራዘሚያ፣ የክ/ከተማና የማዕከል ሪፖርቶች ግምገማ፣ ጊዜያትን መቆለፍና መክፈት፣ ቅጾችና ቅንብሮች። ዲጂታል አገልግሎቶችን አያረጋግጥም።'),
            ServiceMonitoringRoles::VERIFIER_ROLE => self::role(self::SCOPE_GLOBAL, ServiceMonitoringRoles::VERIFIER_PERMISSIONS,
                'Innovation & Technology Bureau: verifies or rejects the digital services the institutions report, city-wide.',
                'የኢኖቬሽንና ቴክኖሎጂ ቢሮ፡ ተቋማት የሚያቀርቧቸውን ዲጂታል አገልግሎቶች በከተማ አቀፍ ደረጃ ያረጋግጣል ወይም ውድቅ ያደርጋል።'),
            ServiceMonitoringRoles::SUPPORT_OFFICER_ROLE => self::role(self::SCOPE_SCOPED, ServiceMonitoringRoles::SUPPORT_OFFICER_PERMISSIONS,
                'Support expert (ደጋፊ ባለሙያ): answers the clarifications of the institutions assigned to them and records monitoring visits, findings and action items.',
                'ደጋፊ ባለሙያ፡ የተመደቡለትን ተቋማት የማብራሪያ ጥያቄዎች ይመልሳል፤ የክትትል ጉብኝቶችን፣ ግኝቶችንና የድርጊት ተግባሮችን ይመዘግባል።'),

            // ── Public feedback, suggestions, tip-offs and complaints (docs/admin-public-feedback-access-control.md) ─
            // Scope decides which institutions; the submitter's identity and evidence files are a
            // separate, audited grant held only by the investigator.
            'Public Feedback Coordinator' => self::role(self::SCOPE_SCOPED, [
                'dashboard.view', ...self::FEEDBACK_READ,
                'service_feedback.assign', 'service_feedback.reassign', 'service_feedback.manage_routing',
                'service_feedback.organization_qr.view', 'service_feedback.organization_qr.create', 'service_feedback.organization_qr.export',
                'service_feedback.organization_qr.disable', 'service_feedback.organization_qr.rotate',
                'service_feedback.review', 'service_feedback.hide', 'service_feedback.export',
            ], 'Intake and routing for the institutions in scope: assigns and reassigns handlers, keeps the routing rules, runs the institution feedback QR, moderates feedback and tip-offs and exports within scope. Does not resolve submissions and never sees who submitted.',
                'በወሰኑ ውስጥ ላሉ ተቋማት መቀበያና መስመር፡ አስተናጋጆችን ይመድባል፣ ያዛውራል፣ የመስመር ደንቦችን ይይዛል፣ የተቋሙን የአስተያየት QR ያካሂዳል፣ አስተያየቶችንና ጥቆማዎችን ይቆጣጠራል፣ በወሰን ውስጥ ወደ ውጭ ይልካል። ማመልከቻዎችን አይፈታም፤ ማን እንዳቀረበ በፍጹም አያይም።'),
            'Public Feedback Handler' => self::role(self::SCOPE_SCOPED, self::FEEDBACK_HANDLER,
                'Handles the public feedback, suggestions, tip-offs and complaints assigned to them in scope: reviews, records the outcome and closes. Never a complaint against themselves; never the submitter\'s identity or evidence files.',
                'በወሰኑ ውስጥ የተመደቡላቸውን የሕዝብ አስተያየቶች፣ ጥቆማዎችና ቅሬታዎች ያስተናግዳል፡ ይገመግማል፣ ውጤቱን ይመዘግባል፣ ይዘጋል። በራሱ ላይ የቀረበ ቅሬታ በፍጹም፤ የአቅራቢውን ማንነት ወይም የማስረጃ ፋይሎች በፍጹም አያይም።'),
            'Public Feedback Investigator' => self::role(self::SCOPE_SCOPED, [
                ...self::FEEDBACK_HANDLER,
                'service_feedback.view_submitter_identity', 'service_feedback.view_sensitive_attachments', 'service_feedback.refer_discipline',
            ], 'Handler with the audited privileges: sees the submitter\'s identity and the evidence files of complaints in scope and may request a controlled referral of a reviewed complaint to the discipline domain.',
                'የሚመዘገቡ ልዩ መብቶች ያሉት አስተናጋጅ፡ በወሰኑ ውስጥ የቅሬታ አቅራቢውን ማንነት እና የማስረጃ ፋይሎች ያያል፤ የተገመገመ ቅሬታን ወደ ዲሲፕሊን ዘርፍ ቁጥጥር ያለው ማስተላለፍ ሊጠይቅ ይችላል።'),
            'Public Feedback Administrator' => self::role(self::SCOPE_GLOBAL, [
                'dashboard.view', ...self::FEEDBACK_READ, 'service_feedback.organization_qr.view',
                'service_feedback.manage_questions', 'service_feedback.settings.manage', 'service_feedback.manage_routing',
                'service_feedback.service_no.override', 'service_feedback.delete', 'service_feedback.export',
            ], 'City-wide configuration of public feedback: the questionnaire versions, feedback settings, city-wide routing rules, service renumbering and the permanent deletion of feedback records. Handles no submission.',
                'የሕዝብ አስተያየት የከተማ አቀፍ ውቅር፡ የመጠይቅ ስሪቶች፣ የአስተያየት ቅንብሮች፣ የከተማ አቀፍ የመስመር ደንቦች፣ የአገልግሎት ቁጥር ማስተካከያ እና የአስተያየት መዝገቦችን ዘላቂ መሰረዝ። ምንም ማመልከቻ አያስተናግድም።'),

            // ── Services and providers (admin side; provider portals use their own roles) ─
            'Service Entitlement Manager' => self::role(self::SCOPE_GLOBAL, [
                'dashboard.view', ...PermissionCatalog::matching('service-types', 'entitlement-rules'),
                'entitlements.viewAny', 'entitlements.view',
            ], 'City-wide catalog of card-based services and who is entitled to them: service types, their performance flag, and the entitlement rules and quotas. Records no transaction.',
                'የመታወቂያ ላይ የተመሠረቱ አገልግሎቶች እና ማን እንደሚገባቸው የከተማ አቀፍ ካታሎግ፡ የአገልግሎት ዓይነቶች፣ የአፈጻጸም ምልክታቸው፣ እና የመብት ደንቦችና ኮታዎች። ምንም ግብይት አይመዘግብም።'),
            'Reception Administrator' => self::role(self::SCOPE_SCOPED, PermissionCatalog::matching('reception'),
                'Configures and oversees reception desks, counters, officers, appointments, queues and scoped reports.',
                'በተፈቀደው ወሰን የመቀበያ ጠረጴዛዎችን፣ መስኮቶችን፣ ሠራተኞችን፣ ቀጠሮዎችን፣ ወረፋዎችን እና ሪፖርቶችን ያስተዳድራል።'),
            'Reception Supervisor' => self::role(self::SCOPE_SCOPED, ['reception.view', 'reception.operate', 'reception.supervise', 'reception.appointments.manage', 'reception.reports.view'],
                'Supervises daily reception queues, counters, appointments and referrals within assigned scope.',
                'በተመደበው ወሰን ዕለታዊ ወረፋዎችን፣ መስኮቶችን፣ ቀጠሮዎችን እና ሪፈራሎችን ይቆጣጠራል።'),
            'Reception Officer' => self::role(self::SCOPE_SCOPED, ['reception.view', 'reception.operate'],
                'Registers and serves visitors at assigned reception desks.',
                'በተመደበበት መቀበያ ጠረጴዛ ጎብኚዎችን ይመዘግባል እና ያስተናግዳል።'),
            'Information Officer' => self::role(self::SCOPE_SCOPED, ['reception.view'],
                'Reads published service information and scoped reception records without changing operations.',
                'የታተመ የአገልግሎት መረጃን እና በወሰን ውስጥ ያሉ መዝገቦችን ሳይቀይር ያነባል።'),
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
                'cafeteria_holidays.create',
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
                'transport-contracts', 'transport-schedules', 'transport-trips', 'transport-eligibility', 'transport-devices',
                'transport-manifests', 'transport-exceptions', 'transport-invoices', 'transport-settlements',
            ), 'Runs the transport service: providers, contracts, routes, stops, schedules, trips, vehicles, drivers, eligibility, devices, boardings, invoices, settlements, reports and settings.',
                'የትራንስፖርት አገልግሎትን ያካሂዳል፡ አቅራቢዎች፣ ውሎች፣ መስመሮች፣ ፌርማታዎች፣ የጊዜ ሰሌዳዎች፣ ጉዞዎች፣ ተሽከርካሪዎች፣ አሽከርካሪዎች፣ ብቁነት፣ መሣሪያዎች፣ መሳፈሮች፣ ደረሰኞች፣ ሂሳብ ማወራረጃዎች፣ ሪፖርቶች እና ቅንብሮች።'),
            'Transport Operator' => self::role(self::SCOPE_SCOPED, [
                'transport-scan.create', 'transport-transactions.viewAny', 'transport-transactions.view',
                'transport-passes.viewAny', 'transport-passes.view', 'transport-trips.viewAny', 'transport-trips.view',
            ], 'Scans cards at boarding and views route assignments and trips. No management rights.',
                'በሚሳፈሩበት ጊዜ መታወቂያ ይቃኛል፣ የመስመር ምደባዎችን እና ጉዞዎችን ይመለከታል። የአስተዳደር መብት የለውም።'),
            'Transport Dispatcher' => self::role(self::SCOPE_SCOPED, [
                'transport-trips.viewAny', 'transport-trips.view', 'transport-trips.create', 'transport-trips.update', 'transport-trips.dispatch',
                'transport-trips.approveExtra', 'transport-schedules.viewAny', 'transport-schedules.view', 'transport-routes.viewAny', 'transport-routes.view',
                'transport-vehicles.viewAny', 'transport-vehicles.view', 'transport-drivers.viewAny', 'transport-drivers.view',
                'transport-manifests.view', 'transport-exceptions.viewAny', 'transport-exceptions.review', 'transport-devices.viewAny', 'transport-devices.view',
                'transport-transactions.viewAny', 'transport-transactions.view', 'transport-reports.view',
            ], 'Plans and monitors trips: assigns vehicles and drivers, starts, completes and cancels trips, replaces vehicles and reviews exceptions.',
                'ጉዞዎችን ያቅዳል እና ይከታተላል፡ ተሽከርካሪዎችን እና አሽከርካሪዎችን ይመድባል፣ ጉዞዎችን ይጀምራል፣ ያጠናቅቃል፣ ይሰርዛል፣ ተሽከርካሪ ይቀይራል እና ልዩ ሁኔታዎችን ይገመግማል።'),
            'Transport Finance Reviewer' => self::role(self::SCOPE_SCOPED, [
                'transport-contracts.viewAny', 'transport-contracts.view', 'transport-invoices.viewAny', 'transport-invoices.view', 'transport-invoices.review',
                'transport-settlements.viewAny', 'transport-settlements.view', 'transport-settlements.manage', 'transport-settlements.approve',
                'transport-transactions.viewAny', 'transport-transactions.view', 'transport-reports.view', 'transport-reports.export',
                'transport-exceptions.viewAny',
            ], 'Reconciles provider invoices against verified usage and the contract, records adjustments and approves statements. A statement is never approved by the person who computed it.',
                'የአቅራቢ ደረሰኞችን ከተረጋገጠ አጠቃቀም እና ከውሉ ጋር ያስታርቃል፣ ማስተካከያዎችን ይመዘግባል እና መግለጫዎችን ያጸድቃል። መግለጫ በሰላው ሰው በፍጹም አይጸድቅም።'),
            'Organization Transport Officer' => self::role(self::SCOPE_SCOPED, [
                'transport-eligibility.viewAny', 'transport-eligibility.view', 'transport-eligibility.create', 'transport-eligibility.update',
                'transport-passes.viewAny', 'transport-passes.view', 'transport-passes.create', 'transport-passes.update', 'transport-passes.cancel',
                'transport-routes.viewAny', 'transport-routes.view', 'transport-schedules.viewAny', 'transport-schedules.view',
                'transport-transactions.viewAny', 'transport-transactions.view', 'transport-reports.view', 'transport-manifests.view',
            ], 'Keeps the organization\'s employees eligible and assigned to routes and stops, and reads its own usage reports. Sees only employees in scope.',
                'የተቋሙን ሠራተኞች ብቁ እና በመስመሮች እና ፌርማታዎች ላይ የተመደቡ ያደርጋል፣ የራሱን የአጠቃቀም ሪፖርቶች ያነባል። በወሰኑ ውስጥ ያሉ ሠራተኞችን ብቻ ያያል።'),
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
                // The settlement screens themselves (read-only): without these the role reached none of them.
                'cafeteria_settlements.view', 'transport-settlements.viewAny', 'transport-settlements.view',
                'transport-invoices.viewAny', 'transport-invoices.view',
            ], 'Reviews provider transactions for settlement. Read-only.',
                'ለክፍያ ማስተካከያ የአቅራቢ ግብይቶችን ይገመግማል። በንባብ ብቻ።'),
            'Cafeteria Provider' => self::role(self::SCOPE_SCOPED, [
                'cafeteria-portal.login', 'cafeteria-portal.view', 'cafeteria-portal.viewDashboard', 'cafeteria-portal.scan',
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

    /**
     * The Amharic name of every default role (roles.name_am). The English name
     * stays the identifier the code checks; this is what the screens show.
     *
     * @var array<string, string>
     */
    public const NAMES_AM = [
        'Super Admin' => 'ዋና አስተዳዳሪ',
        'System Admin' => 'የሥርዓት አስተዳዳሪ',
        'City Admin' => 'የከተማ አስተዳዳሪ',
        'Public Service Bureau Admin' => 'የሲቪል ሰርቪስ ቢሮ አስተዳዳሪ',
        'Organizational Admin' => 'የተቋም አስተዳዳሪ',
        'HR Officer' => 'የሰው ሀብት ባለሙያ',
        'Transfer Vacancy Officer' => 'የዝውውር ክፍት ቦታ ባለሙያ',
        'Transfer Approver' => 'የዝውውር አጽዳቂ',
        'Transfer Final Approver' => 'የዝውውር የመጨረሻ አጽዳቂ',
        'Transfer Administrator' => 'የዝውውር አስተዳዳሪ',
        'Vacancy Officer' => 'የክፍት ቦታ ባለሙያ',
        'Vacancy Selection Approver' => 'የክፍት ቦታ ምርጫ አጽዳቂ',
        'Organization Designer' => 'የአደረጃጀት ንድፍ አዘጋጅ',
        'Organization Design Approver' => 'የአደረጃጀት ንድፍ አጽዳቂ',
        'Design Catalog Manager' => 'የንድፍ ካታሎግ አስተዳዳሪ',
        'Structure Change Requester' => 'የመዋቅር ለውጥ ጠያቂ',
        'Structure Change Reviewer' => 'የመዋቅር ለውጥ ገምጋሚ',
        'Structure Implementation Officer' => 'የመዋቅር ትግበራ ባለሙያ',
        'Organization Registrar' => 'የተቋም መዝጋቢ',
        'Structure Catalog Manager' => 'የመዋቅር ካታሎግ አስተዳዳሪ',
        'Code Rule Administrator' => 'የኮድ ደንብ አስተዳዳሪ',
        'Functional Supervisor' => 'የተግባር ተቆጣጣሪ',
        'ID Card Officer' => 'የመታወቂያ ካርድ ባለሙያ',
        'ID Card Approver' => 'የመታወቂያ ካርድ አጽዳቂ',
        'NFC Provisioning Officer' => 'የNFC ዝግጅት ባለሙያ',
        'NFC Terminal Administrator' => 'የNFC ተርሚናል አስተዳዳሪ',
        'Security Settings Manager' => 'የደህንነት ቅንብሮች አስተዳዳሪ',
        'System Settings Admin' => 'የሥርዓት ቅንብሮች አስተዳዳሪ',
        'API Manager' => 'የAPI አስተዳዳሪ',
        'Auditor' => 'ኦዲተር',
        'Report Viewer' => 'የሪፖርት ተመልካች',
        'Public Site Manager' => 'የሕዝብ ድረ-ገጽ አስተዳዳሪ',
        'Employee' => 'ሠራተኛ',
        'Communication Moderator' => 'የኮሙኒኬሽን አወያይ',
        'Communication Investigator' => 'የኮሙኒኬሽን መርማሪ',
        'Communication Privacy Officer' => 'የኮሙኒኬሽን ግላዊነት ባለሙያ',
        'Supervisor' => 'ኃላፊ',
        'Daily Activity Reviewer' => 'የዕለታዊ እንቅስቃሴ ገምጋሚ',
        'Field Work Location Auditor' => 'የመስክ ሥራ ቦታ ኦዲተር',
        'Performance Manager' => 'የአፈጻጸም ኃላፊ',
        'Performance Officer' => 'የአፈጻጸም ባለሙያ',
        'Performance Reviewer' => 'የአፈጻጸም ገምጋሚ',
        'Performance Calibrator' => 'የአፈጻጸም አመጣጣኝ',
        'Assessment Oversight Officer' => 'የምዘና ክትትል ባለሙያ',
        'Assessment Report Viewer' => 'የምዘና ሪፖርት ተመልካች',
        'Performance Appeal Committee' => 'የአፈጻጸም ይግባኝ ኮሚቴ',
        'Assessment Administrator' => 'የምዘና አስተዳዳሪ',
        'Assessment Data Auditor' => 'የምዘና መረጃ ኦዲተር',
        'Grievance Officer' => 'የቅሬታ ባለሙያ',
        'Grievance Committee Member' => 'የቅሬታ ኮሚቴ አባል',
        'Grievance Committee Writer' => 'የቅሬታ ኮሚቴ ጸሐፊ',
        'Grievance Committee Chairperson' => 'የቅሬታ ኮሚቴ ሰብሳቢ',
        'Grievance Executive Approver' => 'የቅሬታ ሥራ አስፈጻሚ አጽዳቂ',
        'Grievance Administrator' => 'የቅሬታ አስተዳዳሪ',
        'Grievance Registry Officer' => 'የቅሬታ መዝገብ ባለሙያ',
        'Grievance Oversight' => 'የቅሬታ ክትትል',
        'Administrative Tribunal Officer' => 'የአስተዳደር ፍርድ ቤት ባለሙያ',
        'Grievance Records Custodian' => 'የቅሬታ መዝገብ ጠባቂ',
        'Court Viewer' => 'የፍርድ ቤት ተመልካች',
        'Court Registrar' => 'የፍርድ ቤት መዝጋቢ',
        'Court Judge' => 'የፍርድ ቤት ዳኛ',
        'Court President' => 'የፍርድ ቤት ፕሬዚዳንት',
        'Court Inspector' => 'የፍርድ ቤት ኢንስፔክተር',
        'Court Administrator' => 'የፍርድ ቤት አስተዳዳሪ',
        'Discipline Case Officer' => 'የዲሲፕሊን ጉዳይ ባለሙያ',
        'Disciplinary Committee Member' => 'የዲሲፕሊን ኮሚቴ አባል',
        'Disciplinary Decision Authority' => 'የዲሲፕሊን ውሳኔ ሰጪ',
        'Service Monitoring Reporter' => 'የአገልግሎት ክትትል ሪፖርት አቅራቢ',
        'Service Monitoring Head' => 'የአገልግሎት ክትትል ኃላፊ',
        'Service Monitoring Reviewer' => 'የአገልግሎት ክትትል ገምጋሚ',
        'Service Monitoring Coordinator' => 'የአገልግሎት ክትትል አስተባባሪ',
        'Innovation & Technology Verifier' => 'የኢኖቬሽንና ቴክኖሎጂ አረጋጋጭ',
        'Support Officer' => 'የድጋፍ ባለሙያ',
        'Public Feedback Coordinator' => 'የሕዝብ አስተያየት አስተባባሪ',
        'Public Feedback Handler' => 'የሕዝብ አስተያየት አስተናጋጅ',
        'Public Feedback Investigator' => 'የሕዝብ አስተያየት መርማሪ',
        'Public Feedback Administrator' => 'የሕዝብ አስተያየት አስተዳዳሪ',
        'Service Entitlement Manager' => 'የአገልግሎት መብት አስተዳዳሪ',
        'Reception Administrator' => 'የእንግዳ መቀበያ አስተዳዳሪ',
        'Reception Supervisor' => 'የእንግዳ መቀበያ ተቆጣጣሪ',
        'Reception Officer' => 'የእንግዳ መቀበያ ባለሙያ',
        'Information Officer' => 'የመረጃ ባለሙያ',
        'Cafeteria Admin' => 'የካፌቴሪያ አስተዳዳሪ',
        'Cafeteria Operator' => 'የካፌቴሪያ ኦፕሬተር',
        'Transport Admin' => 'የትራንስፖርት አስተዳዳሪ',
        'Transport Operator' => 'የትራንስፖርት ኦፕሬተር',
        'Transport Dispatcher' => 'የትራንስፖርት አሰማሪ',
        'Transport Finance Reviewer' => 'የትራንስፖርት ፋይናንስ ገምጋሚ',
        'Organization Transport Officer' => 'የተቋም ትራንስፖርት ባለሙያ',
        'Service Provider Manager' => 'የአገልግሎት አቅራቢ አስተዳዳሪ',
        'Service Provider User' => 'የአገልግሎት አቅራቢ ተጠቃሚ',
        'Settlement Officer' => 'የሂሳብ ማወራረድ ባለሙያ',
        'Cafeteria Provider' => 'የካፌቴሪያ አቅራቢ',
        'Situation Room Viewer' => 'የሁኔታ ክፍል ተመልካች',
        'Situation Room Executive' => 'የሁኔታ ክፍል ሥራ አስፈጻሚ',
        'Intelligence Analyst' => 'የኢንተለጀንስ ተንታኝ',
        'Intelligence Governance Officer' => 'የኢንተለጀንስ አስተዳደር ባለሙያ',
        'Workflow Policy Administrator' => 'የሥራ ፍሰት ፖሊሲ አስተዳዳሪ',
        'Recycle Bin Manager' => 'የመጣያ ቅርጫት አስተዳዳሪ',
        'Help Desk Agent' => 'የእርዳታ ዴስክ ወኪል',
        'Platform Operations Engineer' => 'የመድረክ ኦፕሬሽን መሐንዲስ',
        'Backup Operator' => 'የመጠባበቂያ ቅጂ ኦፕሬተር',
        'Recovery Requester' => 'የመልሶ ማግኛ ጠያቂ',
        'Recovery Reviewer' => 'የመልሶ ማግኛ ገምጋሚ',
        'Recovery Approver' => 'የመልሶ ማግኛ አጽዳቂ',
    ];

    public static function nameAm(string $role): ?string
    {
        return self::NAMES_AM[$role] ?? null;
    }
}
