import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react';
import { Link, usePage } from '@inertiajs/react';
import {
    AcademicCapIcon,
    AlarmIcon,
    AppealIcon,
    ArchiveIcon,
    ArrowLeftRightIcon,
    BadgeCheckIcon,
    BellIcon,
    BookIcon,
    BoxesIcon,
    BranchingUpIcon,
    Briefcase,
    Building2,
    BusIcon,
    CalendarDateIcon,
    CalendarIcon,
    CalendarMarkIcon,
    CarIcon,
    CardReceiveIcon,
    CardSearchIcon,
    CardTransferIcon,
    ChartIcon,
    ChecklistIcon,
    ChefHatIcon,
    ChevronDown,
    ChevronRight,
    CityIcon,
    ClipboardCheckIcon,
    ClipboardClockIcon,
    ClipboardListIcon,
    ClipboardRemoveIcon,
    ClipboardTextIcon,
    CodeIcon,
    ComplaintIcon,
    ConfettiIcon,
    CreditCard,
    CupHotIcon,
    DatabaseIcon,
    DiplomaIcon,
    DocumentsIcon,
    EyeIcon,
    EyeScanIcon,
    FeedbackIcon,
    FileChartIcon,
    FileCheckIcon,
    FilePenIcon,
    FileSearchIcon,
    FileSendIcon,
    FileSpreadsheetIcon,
    FileTextIcon,
    FileUserIcon,
    FolderCheckIcon,
    FolderFilesIcon,
    FolderOpenIcon,
    GavelIcon,
    GitBranchIcon,
    GitForkIcon,
    GlobalIcon,
    GrievanceIcon,
    HandHeartIcon,
    HandMoneyIcon,
    HandStarsIcon,
    HandshakeIcon,
    HashIcon,
    HelpIcon,
    HistoryIcon,
    IdCardIcon,
    ImportIcon,
    Inbox,
    InboxInIcon,
    InboxOutIcon,
    KeyIcon,
    Layers,
    LayoutDashboard,
    LightbulbIcon,
    LinkIcon,
    ListCheckIcon,
    ListCheckMiniIcon,
    LockIcon,
    MagnifierCheckIcon,
    MailIcon,
    MapIcon,
    MapPinned,
    MapPointAddIcon,
    MedalIcon,
    MedalStarIcon,
    MegaphoneIcon,
    MessageSquareIcon,
    MonitorIcon,
    NetworkIcon,
    NfcIcon,
    NotebookIcon,
    PaletteIcon,
    PenIcon,
    PenNewIcon,
    PenNewRoundIcon,
    PieChartIcon,
    PinListIcon,
    PrinterIcon,
    QrCodeIcon,
    QuestionnaireIcon,
    RadarIcon,
    ActivityIcon,
    RankingIcon,
    ReceiptTextIcon,
    RestartIcon,
    RouteIcon,
    RulerPenIcon,
    ScaleIcon,
    SearchIcon,
    SettingsIcon,
    ShieldCheck,
    ShieldUserIcon,
    ShieldWarningIcon,
    ShopIcon,
    SignpostIcon,
    SpeedometerIcon,
    StarIcon,
    SteeringWheelIcon,
    StopwatchIcon,
    SuitcaseTagIcon,
    TagsIcon,
    TargetIcon,
    TicketIcon,
    ToolboxIcon,
    TrashIcon,
    TrendingUpIcon,
    TuningIcon,
    UploadIcon,
    UserBlockIcon,
    UserCheckIcon,
    UserCircleIcon,
    UserHandsIcon,
    UserIcon,
    UserPlusIcon,
    UserSpeakIcon,
    Users,
    UsersTwoIcon,
    WalletIcon,
    WidgetsIcon,
    WifiRouterIcon,
    X,
} from '@/Components/NavIcons';
import { type JSX, type ReactNode, type SVGProps, useEffect, useId, useRef, useState } from 'react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import { useCan } from '@/hooks/useCan';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import { useBranding } from '@/hooks/useBranding';

/** A leaf nav item — renders as a clickable link. */
type NavSubItem = {
    routeName: string;
    labelKey: string;
    icon: (p: SVGProps<SVGSVGElement>) => JSX.Element;
    permission?: string;
    /** Shown when the user holds any one of these (for pages that accept several). */
    anyPermission?: string[];
    tab?: string;
    /** Also listed in an admin group, so staff see it there and not twice. */
    listedInAdmin?: boolean;
    /** The page serves the signed-in employee's own record, so accounts without one never see it. */
    employeeOnly?: boolean;
    /** City-level page: the server refuses organization-scoped users even when they hold the permission. */
    citywideOnly?: boolean;
    /** Unread count shown as a badge (Communication). */
    badge?: number | null;
};

/** A nav item — either a plain link OR a parent with expandable children. */
type NavItem = NavSubItem & {
    /** When present, this item renders as a dropdown toggle instead of a link. */
    children?: NavSubItem[];
};

type NavGroup = {
    key: string;
    labelKey: string;
    icon: (p: SVGProps<SVGSVGElement>) => JSX.Element;
    items: NavItem[];
};

/*
 * My Portal navigation, categorized like the admin sidebar: labeled sections,
 * collapsible groups, pages. Employee-only accounts get exactly this; staff
 * who are also employees get the same pages as one "Self-service" group
 * (selfServiceNav below), so every page is defined once.
 *
 * Deliberately absent (the routes still work when linked from a page):
 * My Profile (the account menu and the dashboard's profile card open it),
 * transfer announcements (reached from My Applications and the dashboard),
 * /my-portal/organization and /my-portal/position (subsets of My Employment),
 * /my-portal/service-tasks (only points to Daily Activity) and
 * /my-portal/security (redirects to the sign-in block of My Profile).
 */
const portalDashboard: NavItem = { routeName: 'employee.portal', labelKey: 'nav.myPortal', icon: LayoutDashboard };

const portalSections: { labelKey: string; groups: NavGroup[] }[] = [
    {
        // Day-to-day work: what the employee records and is measured on.
        labelKey: 'nav.portalSectionWork',
        groups: [
            {
                key: 'portalDailyActivity',
                labelKey: 'nav.myDailyActivity',
                icon: NotebookIcon,
                items: [
                    { routeName: 'employee.daily-activity.entry', labelKey: 'nav.registerActivity', icon: PenNewIcon, permission: 'daily_activities.view_own' },
                    { routeName: 'employee.daily-activity.calendar', labelKey: 'nav.myActivityCalendar', icon: CalendarIcon, permission: 'daily_activities.view_own' },
                    { routeName: 'employee.daily-activity.history', labelKey: 'nav.myActivityHistory', icon: HistoryIcon, permission: 'daily_activities.view_own' },
                ],
            },
            {
                // Own field work only. Supervising and overseeing it is the
                // admin "Field Work Management" group, never this one.
                key: 'portalFieldWork',
                labelKey: 'nav.groupMyFieldWork',
                icon: MapPinned,
                items: [
                    { routeName: 'employee.field-work.index', labelKey: 'nav.myFieldWork', icon: MapIcon, permission: 'field_work.view_own' },
                    { routeName: 'employee.field-work.create', labelKey: 'nav.newFieldWork', icon: MapPointAddIcon, permission: 'field_work.create_own' },
                    { routeName: 'employee.field-work.history', labelKey: 'nav.myFieldWorkHistory', icon: RouteIcon, permission: 'field_work.view_own' },
                ],
            },
            {
                key: 'portalPerformance',
                labelKey: 'nav.myPerformance',
                icon: TrendingUpIcon,
                items: [
                    { routeName: 'employee.performance.index', labelKey: 'nav.myPerformance', icon: TrendingUpIcon, permission: 'employee_performance_agreements.view_own' },
                    { routeName: 'employee.assessments.index', labelKey: 'nav.myAssessments', icon: MedalStarIcon, permission: 'assessments.view_own_result' },
                    // Peer evaluators are ordinary staff: without this link they could reach their rating tasks only from a notification.
                    { routeName: 'assessment-workspace.index', labelKey: 'nav.assignedAssessments', icon: PenNewRoundIcon, permission: 'assessments.view_assigned', listedInAdmin: true },
                ],
            },
        ],
    },
    {
        // What HR holds about the employee.
        labelKey: 'nav.portalSectionRecords',
        groups: [
            {
                key: 'portalRecords',
                labelKey: 'nav.groupMyRecords',
                icon: UserIcon,
                items: [
                    { routeName: 'employee.employment', labelKey: 'nav.myEmployment', icon: Briefcase },
                    { routeName: 'employee.id-card', labelKey: 'nav.myIdCard', icon: IdCardIcon },
                    { routeName: 'employee.documents', labelKey: 'nav.myDocuments', icon: DocumentsIcon },
                ],
            },
        ],
    },
    {
        // What the employee can use, apply for or ask for.
        labelKey: 'nav.portalSectionServices',
        groups: [
            {
                key: 'portalServices',
                labelKey: 'nav.myEntitlements',
                icon: HandStarsIcon,
                items: [
                    { routeName: 'employee.services', labelKey: 'nav.myEntitlements', icon: HandStarsIcon },
                ],
            },
            {
                key: 'portalCareer',
                labelKey: 'nav.groupCareer',
                icon: ArrowLeftRightIcon,
                items: [
                    { routeName: 'employee.transfer-applications', labelKey: 'nav.myTransferApplications', icon: FileSendIcon },
                ],
            },
            {
                key: 'portalRequests',
                labelKey: 'nav.groupRequestsMessages',
                icon: MessageSquareIcon,
                items: [
                    { routeName: 'employee.requests', labelKey: 'nav.myRequests', icon: InboxOutIcon },
                    { routeName: 'employee.grievances.index', labelKey: 'nav.myGrievances', icon: GrievanceIcon, permission: 'grievances.view_own', listedInAdmin: true },
                    { routeName: 'employee.discipline.index', labelKey: 'nav.myDisciplinaryCases', icon: ScaleIcon, permission: 'discipline.view_own', listedInAdmin: true },
                    { routeName: 'employee.notifications', labelKey: 'nav.myNotifications', icon: BellIcon },
                ],
            },
        ],
    },
];

/** Staff with an employee record: the same pages as one group, in the same order. */
const selfServiceNav: NavItem[] = [
    portalDashboard,
    ...portalSections.flatMap((section) => section.groups.flatMap((group) => group.items)).filter((item) => !item.listedInAdmin),
];

/*
 * Sidebar categories.
 *
 * Grouped by what an administrator is trying to DO, not by which table a page
 * touches: HR reference data (grades, occupations, positions) is separated from
 * day-to-day employee work, and everything facing an outside party sits
 * together under Providers.
 *
 * Rendering is unchanged. Each item is hidden when the user lacks its
 * permission, and a group whose items are all hidden disappears with it, so no
 * empty headers appear. Public pages (/id-checker, /service-feedback) are
 * deliberately absent: they are for citizens, not administrators.
 */
const navGroups: NavGroup[] = [
    {
        // The same entries as employee-only mode, never a second list.
        key: 'myWork',
        labelKey: 'nav.groupSelfService',
        icon: UserIcon,
        items: [
            ...selfServiceNav,
            // Every signed-in user may file and read their own support tickets (docs/assistant/support-helpdesk.md).
            { routeName: 'support.tickets.index', labelKey: 'nav.supportTickets', icon: HelpIcon },
        ],
    },
    {
        /*
         * Executive Situation Room: leadership aggregates inside the viewer's
         * organization scope. Its categories are listed on the launcher
         * (SituationCategory registry), so only the entry points live here.
         */
        key: 'situationRoom',
        labelKey: 'nav.groupSituationRoom',
        icon: RadarIcon,
        items: [
            { routeName: 'situation-room.index', labelKey: 'nav.situationRoom', icon: LayoutDashboard, permission: 'situation_room.view' },
            { routeName: 'situation-room.community-voice', labelKey: 'nav.situationRoomCommunityVoice', icon: UserSpeakIcon, permission: 'situation_room.public_voice.view' },
            { routeName: 'situation-room.wall', labelKey: 'nav.situationRoomWall', icon: MonitorIcon, permission: 'situation_room.wall_mode' },
            { routeName: 'situation-room.actions.index', labelKey: 'nav.situationRoomActions', icon: ChecklistIcon, permission: 'situation_room.view' },
            { routeName: 'situation-room.reports', labelKey: 'nav.situationRoomReports', icon: FileChartIcon, permission: 'situation_room.export' },
        ],
    },
    {
        key: 'intelligence',
        labelKey: 'nav.groupIntelligence',
        icon: LightbulbIcon,
        items: [
            { routeName: 'intelligence.index', labelKey: 'nav.intelligence', icon: LayoutDashboard, permission: 'intelligence.view' },
            // Each hub has a parameter-less route of its own; the {area} wildcard needs a parameter the sidebar cannot give.
            { routeName: 'intelligence.system-health', labelKey: 'nav.intelligenceSystemHealth', icon: ActivityIcon, permission: 'intelligence.system-health.view' },
            { routeName: 'intelligence.incidents.index', labelKey: 'nav.intelligenceIncidents', icon: AlarmIcon, permission: 'intelligence.incidents.view' },
            { routeName: 'intelligence.knowledge.index', labelKey: 'nav.intelligenceKnowledge', icon: BookIcon, permission: 'intelligence.knowledge.view' },
            { routeName: 'intelligence.governance.index', labelKey: 'nav.intelligenceGovernance', icon: ShieldUserIcon, permission: 'intelligence.governance.view' },
        ],
    },
    {
        key: 'organization',
        labelKey: 'nav.groupOrganization',
        icon: CityIcon,
        items: [
            { routeName: 'organizations.index', labelKey: 'nav.organizations', icon: Building2,     permission: 'organizations.view' },
            { routeName: 'organization-types.index', labelKey: 'nav.organizationTypes', icon: TagsIcon,      permission: 'organization-types.viewAny' },
            { routeName: 'organization-units.index', labelKey: 'nav.organizationUnits', icon: GitBranchIcon, permission: 'organization-units.viewAny' },
            { routeName: 'organization-unit-types.index', labelKey: 'nav.organizationUnitTypes', icon: BoxesIcon, permission: 'organization-unit-types.viewAny' },
            { routeName: 'hierarchy-versions.index', labelKey: 'nav.hierarchyVersions', icon: GitForkIcon,   permission: 'hierarchy-versions.viewAny' },
            /*
             * Change requests. Four entries, each gated by the permission for
             * that stage, so a requester never sees the review or
             * implementation queues and vice versa.
             */
            { routeName: 'organizational-change-requests.index', labelKey: 'nav.changeRequestsMine', icon: FilePenIcon, permission: 'organizational-change-requests.view_own' },
            { routeName: 'organizational-change-requests.review-queue', labelKey: 'nav.changeRequestsReview', icon: FileSearchIcon, permission: 'organizational-change-requests.review' },
            { routeName: 'organizational-change-requests.pending-implementation', labelKey: 'nav.changeRequestsImplement', icon: ToolboxIcon, permission: 'organizational-change-requests.view_approved' },
            { routeName: 'organizational-change-requests.completed', labelKey: 'nav.changeRequestsCompleted', icon: FileCheckIcon, permission: 'organizational-change-requests.view_own' },
            /*
             * The controller also accepts `functional-reporting.viewReports`;
             * only one permission can gate a nav entry, so the broader of the
             * two is used and the page re-checks both on arrival.
             */
            { routeName: 'reporting-lines.index', labelKey: 'nav.reportingLines', icon: BranchingUpIcon,   permission: 'relationships.viewAny' },
        ],
    },
    {
        /*
         * Reference data that changes rarely and underpins everything else.
         * Kept apart from Employee Management so daily HR work is not buried
         * among catalog screens.
         */
        key: 'hrMasterData',
        labelKey: 'nav.groupHrMasterData',
        icon: ArchiveIcon,
        items: [
            { routeName: 'positions.index', labelKey: 'nav.positions', icon: Briefcase,          permission: 'positions.viewAny' },
            { routeName: 'positions.status', labelKey: 'nav.newJobPositionsStatus', icon: SuitcaseTagIcon, permission: 'positions.viewAny' },
            { routeName: 'position-establishments.index', labelKey: 'nav.positionEstablishments', icon: ClipboardTextIcon, permission: 'position-establishments.viewAny' },
            { routeName: 'position-services.index', labelKey: 'nav.positionServices', icon: Layers,             permission: 'service_feedback.settings.manage' },
            { routeName: 'grade-levels.index', labelKey: 'nav.gradeLevels', icon: RankingIcon,     permission: 'grade-levels.viewAny' },
            { routeName: 'occupations.index', labelKey: 'nav.occupations', icon: AcademicCapIcon,        permission: 'occupations.viewAny' },
            { routeName: 'isic-activities.index', labelKey: 'nav.isicActivities', icon: ShopIcon,       permission: 'isic-activities.viewAny' },
        ],
    },
    /*
     * Employee Performance Management (EPMS). Each entry is gated by the
     * permission its page checks; the pages re-check scope server-side.
     */
    {
        key: 'performance',
        labelKey: 'nav.groupPerformance',
        icon: TrendingUpIcon,
        items: [
            // Line managers (agreements) use the dashboard for their teams, as the page allows.
            { routeName: 'performance.dashboard', labelKey: 'nav.performanceDashboard', icon: LayoutDashboard, anyPermission: ['performance_reports.view', 'employee_performance_agreements.manage'] },
            { routeName: 'performance.cycles.index', labelKey: 'nav.performanceCycles', icon: CalendarIcon, permission: 'performance_cycles.view' },
            { routeName: 'performance.strategic-goals.index', labelKey: 'nav.strategicGoals', icon: TargetIcon, permission: 'strategic_goals.view' },
            { routeName: 'performance.plans.index', labelKey: 'nav.performancePlans', icon: ClipboardListIcon, permission: 'performance_plans.view' },
            { routeName: 'performance.kpis.index', labelKey: 'nav.kpiLibrary', icon: SpeedometerIcon, permission: 'kpis.view' },
            { routeName: 'performance.agreements.index', labelKey: 'nav.performanceAgreements', icon: HandshakeIcon, permission: 'employee_performance_agreements.manage' },
            { routeName: 'performance.calibration.index', labelKey: 'nav.performanceCalibration', icon: TuningIcon, permission: 'performance_calibration.view' },
            { routeName: 'performance.appeals.index', labelKey: 'nav.performanceAppeals', icon: AppealIcon, anyPermission: ['performance_appeals.review', 'performance_appeals.decide'] },
            { routeName: 'performance.reports.index', labelKey: 'nav.performanceReports', icon: FileChartIcon, permission: 'performance_reports.view' },
            { routeName: 'performance.settings.index', labelKey: 'nav.performanceSettings', icon: SettingsIcon, permission: 'performance_settings.view' },
        ],
    },
    /*
     * Assessment Management: every competency / behavioural assessment page
     * in one group of its own, beside (not inside) Performance Management:
     * forms and assessments first, then oversight & compliance. Pages
     * re-check permission and scope.
     */
    {
        key: 'assessments',
        labelKey: 'nav.groupAssessmentManagement',
        icon: ListCheckIcon,
        items: [
            { routeName: 'assessment-forms.index', labelKey: 'nav.assessmentForms', icon: ClipboardListIcon, permission: 'assessment_forms.view' },
            { routeName: 'assessment-workspace.index', labelKey: 'nav.assignedAssessments', icon: PenNewRoundIcon, permission: 'assessments.view_assigned' },
            { routeName: 'assessment-reviews.index', labelKey: 'nav.assessmentReviews', icon: EyeIcon, anyPermission: ['assessments.review', 'assessment_assignments.view'] },
            { routeName: 'assessment-records.index', labelKey: 'nav.assessmentRecords', icon: FolderCheckIcon, anyPermission: ['assessment_forms.edit_draft', 'assessment_forms.publish'] },
            { routeName: 'assessment-oversight.dashboard', labelKey: 'nav.assessmentOversightDashboard', icon: LayoutDashboard, permission: 'assessment_oversight.view_dashboard' },
            { routeName: 'assessment-oversight.institutions', labelKey: 'nav.assessmentOversightInstitutions', icon: Building2, permission: 'assessment_oversight.view_institutions' },
            { routeName: 'assessment-oversight.employees', labelKey: 'nav.assessmentOversightCoverage', icon: UserCheckIcon, permission: 'assessment_oversight.view_employee_status' },
            { routeName: 'assessment-oversight.distribution', labelKey: 'nav.assessmentOversightDistribution', icon: ChartIcon, permission: 'assessment_oversight.view_results' },
            { routeName: 'assessment-oversight.gender', labelKey: 'nav.assessmentOversightGender', icon: UsersTwoIcon, permission: 'assessment_oversight.view_demographics' },
            { routeName: 'assessment-oversight.data-quality', labelKey: 'nav.assessmentOversightDataQuality', icon: MagnifierCheckIcon, permission: 'assessment_oversight.view_data_quality' },
            { routeName: 'assessment-oversight.submissions', labelKey: 'nav.assessmentOversightSubmissions', icon: UploadIcon, anyPermission: ['assessment_submissions.submit', 'assessment_submissions.review', 'assessment_submissions.verify', 'assessment_submissions.finalize'] },
            { routeName: 'assessment-oversight.reports', labelKey: 'nav.assessmentOversightReports', icon: FileChartIcon, permission: 'assessment_reports.view' },
            { routeName: 'assessment-oversight.setup', labelKey: 'nav.assessmentOversightSetup', icon: SettingsIcon, anyPermission: ['assessment_oversight.manage_cycles', 'assessment_oversight.manage_policies'] },
        ],
    },
    /*
     * Organization Design & Workforce Planning: the design chain from the
     * legal mandate down to job descriptions. Every page re-checks scope.
     */
    {
        key: 'organizationDesign',
        labelKey: 'nav.groupOrganizationDesign',
        icon: RulerPenIcon,
        items: [
            { routeName: 'organization-design.overview', labelKey: 'nav.designOverview', icon: MapIcon, anyPermission: ['legacy-baselines.viewAny', 'legal-sources.viewAny', 'institution-mandates.viewAny', 'service-catalog.viewAny', 'processes.viewAny', 'organization-design.viewAny', 'workforce-planning.viewAny', 'position-profiles.viewAny', 'job-descriptions.viewAny', 'design-imports.viewAny', 'organization-design-reports.view', 'data-mapping.view'] },
            { routeName: 'organization-design.legacy-baselines.index', labelKey: 'nav.designLegacyBaselines', icon: ArchiveIcon, permission: 'legacy-baselines.viewAny' },
            { routeName: 'organization-design.legal-sources.index', labelKey: 'nav.designLegalSources', icon: ScaleIcon, permission: 'legal-sources.viewAny' },
            { routeName: 'organization-design.imports.index', labelKey: 'nav.designImports', icon: ImportIcon, permission: 'design-imports.viewAny' },
            { routeName: 'organization-design.mandates.index', labelKey: 'nav.designMandates', icon: DiplomaIcon, permission: 'institution-mandates.viewAny' },
            { routeName: 'organization-design.functions.index', labelKey: 'nav.designFunctions', icon: WidgetsIcon, permission: 'organization-functions.viewAny' },
            { routeName: 'organization-design.services.index', labelKey: 'nav.designServices', icon: Layers, permission: 'service-catalog.viewAny' },
            { routeName: 'organization-design.classifications.index', labelKey: 'nav.designClassifications', icon: TagsIcon, permission: 'classifications.viewAny' },
            { routeName: 'organization-design.processes.index', labelKey: 'nav.designProcesses', icon: RouteIcon, permission: 'processes.viewAny' },
            { routeName: 'organization-design.professions.index', labelKey: 'nav.designProfessions', icon: AcademicCapIcon, anyPermission: ['occupations.viewAny', 'professions.manage'] },
            { routeName: 'organization-design.competency-catalog.index', labelKey: 'nav.designCompetencyCatalog', icon: MedalIcon, anyPermission: ['competency-catalog.viewAny', 'competency-catalog.manage'] },
            { routeName: 'organization-design.studies.index', labelKey: 'nav.designStudies', icon: DocumentsIcon, permission: 'organization-design.viewAny' },
            { routeName: 'organization-design.responsibilities.index', labelKey: 'nav.designResponsibilities', icon: UserHandsIcon, permission: 'organization-design.viewAny' },
            { routeName: 'organization-design.structure.index', labelKey: 'nav.designStructure', icon: GitForkIcon, permission: 'organization-design.viewAny' },
            { routeName: 'organization-design.methodologies.index', labelKey: 'nav.designMethodologies', icon: BookIcon, permission: 'methodologies.viewAny' },
            { routeName: 'organization-design.manpower-plans.index', labelKey: 'nav.designManpowerPlans', icon: Users, permission: 'workforce-planning.viewAny' },
            { routeName: 'organization-design.position-design.index', labelKey: 'nav.designPositions', icon: Briefcase, anyPermission: ['position-profiles.viewAny', 'job-descriptions.viewAny'] },
            { routeName: 'organization-design.reports.index', labelKey: 'nav.designReports', icon: FileChartIcon, permission: 'organization-design-reports.view' },
            { routeName: 'organization-design.mapping.index', labelKey: 'nav.designMapping', icon: LinkIcon, permission: 'data-mapping.view' },
        ],
    },
    {
        key: 'employeeManagement',
        labelKey: 'nav.groupEmployeeManagement',
        icon: UsersTwoIcon,
        items: [
            { routeName: 'employees.index', labelKey: 'nav.employees', icon: Users,              permission: 'employees.view' },
            { routeName: 'employees.import.create', labelKey: 'nav.employeeImport', icon: ImportIcon,  permission: 'employees.import.view' },
            { routeName: 'vacancy-announcements.index', labelKey: 'nav.vacancyAnnouncements', icon: MegaphoneIcon,      permission: 'vacancy-announcements.viewAny' },
            { routeName: 'vacancy-applications.index', labelKey: 'nav.vacancyApplications', icon: FileUserIcon, permission: 'vacancy-applications.viewAny' },
            { routeName: 'vacancy-applications.my-applications', labelKey: 'nav.myApplications',        icon: FileSendIcon },
        ],
    },
    {
        /*
         * Daily Activity Register: its own module, not employee records. The
         * dashboard and missing list are gated by the broadest permission that
         * reaches them; each page re-checks the exact permission server-side.
         */
        key: 'dailyActivity',
        labelKey: 'nav.groupDailyActivity',
        icon: NotebookIcon,
        items: [
            { routeName: 'daily-activities.dashboard', labelKey: 'nav.dailyActivityDashboard', icon: LayoutDashboard, anyPermission: ['daily_activities.view_reports', 'daily_activities.view_team', 'daily_activities.view_scoped'] },
            { routeName: 'daily-activities.index', labelKey: 'nav.dailyActivityRegister', icon: ClipboardListIcon, anyPermission: ['daily_activities.view_scoped', 'daily_activities.view_team'] },
            { routeName: 'daily-activities.missing', labelKey: 'nav.dailyActivityMissing', icon: ClipboardRemoveIcon, anyPermission: ['daily_activities.view_reports', 'daily_activities.view_scoped', 'daily_activities.view_team'] },
            { routeName: 'daily-activities.review-queue', labelKey: 'nav.dailyActivityReviewQueue', icon: ClipboardCheckIcon, permission: 'daily_activities.review' },
            { routeName: 'daily-activities.reports', labelKey: 'nav.dailyActivityReports', icon: FileChartIcon, permission: 'daily_activities.view_reports' },
            { routeName: 'daily-activities.reviewers.index', labelKey: 'nav.dailyActivityReviewers', icon: UserCheckIcon, permission: 'daily_activities.manage_reviewers' },
            { routeName: 'daily-activities.settings', labelKey: 'nav.dailyActivitySettings', icon: SettingsIcon, anyPermission: ['daily_activity_settings.view', 'daily_activity_settings.update'] },
        ],
    },
    {
        /*
         * Field Work Management (docs/field-work-navigation.md): the ONE admin
         * category for supervising and overseeing field work. Employees' own
         * requests live in My Portal above, never here. Each entry is gated by
         * the permission its page checks; the page re-checks permission,
         * scope and the record server-side.
         */
        key: 'fieldWork',
        labelKey: 'nav.fieldWorkManagement',
        icon: MapPinned,
        items: [
            { routeName: 'field-work.dashboard', labelKey: 'nav.fieldWorkDashboard', icon: LayoutDashboard, anyPermission: ['field_work.view_team', 'field_work.view_org'] },
            { routeName: 'field-work.requests.index', labelKey: 'nav.fieldWorkRequests', icon: ClipboardListIcon, anyPermission: ['field_work.view_team', 'field_work.view_org'] },
            { routeName: 'field-work.pending', labelKey: 'nav.fieldWorkApprovals', icon: ClipboardCheckIcon, anyPermission: ['field_work.approve', 'field_work.return', 'field_work.reject'] },
            { routeName: 'field-work.team.index', labelKey: 'nav.fieldWorkTeam', icon: Users, permission: 'field_work.view_team' },
            { routeName: 'field-work.availability.index', labelKey: 'nav.fieldWorkAvailability', icon: UserCheckIcon, anyPermission: ['field_work.view_team', 'field_work.view_org'] },
            { routeName: 'field-work.overdue.index', labelKey: 'nav.fieldWorkOverdue', icon: AlarmIcon, anyPermission: ['field_work.view_team', 'field_work.view_org'] },
            { routeName: 'field-work.types.index', labelKey: 'nav.fieldWorkTypes', icon: TagsIcon, permission: 'field_work.manage_types' },
        ],
    },
    {
        key: 'transferManagement',
        labelKey: 'nav.transferManagement',
        icon: ArrowLeftRightIcon,
        items: [
            { routeName: 'transfers.dashboard', labelKey: 'nav.transferDashboard', icon: LayoutDashboard, permission: 'transfers.view' },
            { routeName: 'transfer-announcements.index', labelKey: 'nav.transferAnnouncements', icon: MegaphoneIcon, permission: 'transfers.announcements.view' },
            { routeName: 'transfer-applications.index', labelKey: 'nav.transferApplications', icon: Inbox,              permission: 'transfers.applications.view' },
            { routeName: 'transfer-settings.show', labelKey: 'nav.transferSettings', icon: SettingsIcon,       permission: 'transfers.settings.manage' },
        ],
    },
    {
        key: 'identity',
        labelKey: 'nav.groupIdentity',
        icon: IdCardIcon,
        items: [
            { routeName: 'id-cards.index', labelKey: 'nav.idCards', icon: CreditCard,         permission: 'cards.view' },
            { routeName: 'id-cards.reprint-required', labelKey: 'nav.reprintRequired', icon: RestartIcon, permission: 'cards.view' },
            { routeName: 'print-batches.index', labelKey: 'nav.printBatches', icon: PrinterIcon, anyPermission: ['id-cards.createPrintBatch', 'cards.manage'] },
            { routeName: 'id-card-templates.index', labelKey: 'nav.idCardTemplates', icon: PaletteIcon, permission: 'id_card_templates.view' },
            { routeName: 'employee-corrections.index', labelKey: 'nav.employeeCorrections', icon: PenIcon, permission: 'employees.manage' },
            { routeName: 'card-requests.index', labelKey: 'nav.cardRequests', icon: CardReceiveIcon, permission: 'id-cards.viewAny' },
            { routeName: 'nfc-management.dashboard', labelKey: 'nav.nfcManagement', icon: NfcIcon,       permission: 'nfc_credentials.view' },
            { routeName: 'nfc-management.credentials.index', labelKey: 'nav.nfcCredentials', icon: CardTransferIcon, permission: 'nfc_credentials.view' },
            { routeName: 'nfc-management.terminals.index', labelKey: 'nav.nfcTerminals', icon: WifiRouterIcon,     permission: 'nfc_terminals.view' },
            { routeName: 'nfc-management.logs.index', labelKey: 'nav.nfcVerificationLogs', icon: CardSearchIcon,     permission: 'nfc_logs.view' },
        ],
    },
    {
        key: 'grievances',
        labelKey: 'nav.groupGrievances',
        icon: GrievanceIcon,
        items: [
            { routeName: 'grievances.dashboard', labelKey: 'nav.grievanceDashboard', icon: LayoutDashboard, anyPermission: ['grievances.view_assigned', 'grievances.intake_review', 'grievances.assign', 'grievances.oversight_view', 'grievances.tribunal', 'grievance_decisions.approve', 'grievance_reports.view'] },
            { routeName: 'employee.grievances.index', labelKey: 'nav.myGrievances', icon: InboxOutIcon, permission: 'grievances.view_own' },
            { routeName: 'grievances.cases.index', tab: 'assigned', labelKey: 'nav.grievanceAssigned', icon: PinListIcon, anyPermission: ['grievances.view_assigned', 'grievances.assign', 'grievances.tribunal'] },
            { routeName: 'grievances.cases.index', tab: 'intake', labelKey: 'nav.grievanceIntake', icon: InboxInIcon, permission: 'grievances.intake_review' },
            { routeName: 'grievances.cases.index', tab: 'authorized', labelKey: 'nav.grievanceAuthorized', icon: EyeIcon, anyPermission: ['grievances.oversight_view', 'grievances.view_assigned', 'grievances.assign'] },
            { routeName: 'grievances.approvals.index', labelKey: 'nav.grievanceApprovals', icon: BadgeCheckIcon, permission: 'grievance_decisions.approve' },
            { routeName: 'grievances.appeals.index', labelKey: 'nav.grievanceAppeals', icon: AppealIcon, anyPermission: ['grievances.view_assigned', 'grievances.intake_review', 'grievances.assign', 'grievances.oversight_view', 'grievances.tribunal', 'grievance_decisions.approve', 'grievance_reports.view'] },
            { routeName: 'grievances.correspondence.index', labelKey: 'nav.grievanceCorrespondence', icon: MailIcon, permission: 'grievance_correspondence.view' },
            { routeName: 'grievances.committees.index', labelKey: 'nav.grievanceCommittees', icon: UsersTwoIcon, permission: 'grievance_committees.view' },
            { routeName: 'grievances.routes.index', labelKey: 'nav.grievanceRouting', icon: SignpostIcon, permission: 'grievance_routes.view' },
            { routeName: 'grievances.sla.index', labelKey: 'nav.grievanceSla', icon: StopwatchIcon, permission: 'grievance_sla.view' },
            { routeName: 'grievances.reports.index', labelKey: 'nav.grievanceReports', icon: FileChartIcon, permission: 'grievance_reports.view' },
            { routeName: 'grievances.settings.index', labelKey: 'nav.grievanceSettings', icon: SettingsIcon, permission: 'grievance_settings.view' },
        ],
    },
    {
        /*
         * Employee Discipline (Regulation 201/2018) is its own domain: never
         * part of Grievance Management or of public service complaints.
         */
        key: 'discipline',
        labelKey: 'nav.groupDiscipline',
        icon: ShieldWarningIcon,
        items: [
            { routeName: 'discipline.cases.index', labelKey: 'nav.disciplineCases', icon: FolderFilesIcon, anyPermission: ['discipline.view', 'discipline.investigate', 'discipline.decide'] },
            { routeName: 'employee.discipline.index', labelKey: 'nav.myDisciplinaryCases', icon: InboxOutIcon, permission: 'discipline.view_own', employeeOnly: true },
        ],
    },
    {
        /*
         * Tribunal Court: external adjudication of administrative cases. Holds
         * the Administrative Tribunal cases (grievance appeals routed to it) and
         * the planned Court Cases module (docs/court-cases.md). Neither is part
         * of Grievance Management's internal review workflow.
         */
        key: 'tribunalCourt',
        labelKey: 'nav.groupTribunalCourt',
        icon: ScaleIcon,
        items: [
            { routeName: 'tribunal-cases.index', labelKey: 'nav.tribunalCases', icon: FolderOpenIcon, permission: 'grievances.tribunal' },
            { routeName: 'court-cases.index', labelKey: 'nav.courtCases', icon: GavelIcon, permission: 'court_cases.view' },
        ],
    },
    {
        /*
         * Client-facing service definitions and the entitlements catalog.
         */
        key: 'serviceManagement',
        labelKey: 'nav.groupServiceManagement',
        icon: HandHeartIcon,
        items: [
            { routeName: 'service-types.index', labelKey: 'nav.serviceTypes', icon: Layers,            permission: 'service-types.viewAny' },
            { routeName: 'entitlements.index', labelKey: 'nav.entitlements',             icon: HandStarsIcon,    permission: 'entitlements.view' },
            { routeName: 'entitlement-rules.index', labelKey: 'nav.entitlementRules', icon: ListCheckMiniIcon,   permission: 'entitlement-rules.viewAny' },
        ],
    },
    {
        /*
         * Public Feedback Management (brief §79–80): what the public said
         * through the employee and institution QR codes, and how it is routed,
         * handled and reported. Independent of grievances and discipline.
         */
        key: 'serviceFeedback',
        labelKey: 'nav.groupPublicFeedback',
        icon: FeedbackIcon,
        items: [
            { routeName: 'service-feedback.admin.dashboard', labelKey: 'nav.serviceFeedbackDashboard', icon: LayoutDashboard, permission: 'service_feedback.view' },
            // Every type the user may read, and the routed ones still without a handler.
            { routeName: 'service-submissions.index', labelKey: 'nav.serviceAllSubmissions', icon: Inbox, anyPermission: ['service_feedback.view', 'service_feedback.complaints.view'] },
            { routeName: 'service-submissions.unrouted', labelKey: 'nav.serviceUnrouted', icon: InboxInIcon, anyPermission: ['service_feedback.view', 'service_feedback.complaints.view'] },
            { routeName: 'service-feedback.admin.index', labelKey: 'nav.serviceFeedbackList', icon: StarIcon, permission: 'service_feedback.view' },
            { routeName: 'service-submissions.suggestions', labelKey: 'nav.serviceSuggestions', icon: LightbulbIcon, permission: 'service_feedback.view' },
            { routeName: 'service-submissions.complaints', labelKey: 'nav.serviceComplaints', icon: ComplaintIcon, permission: 'service_feedback.complaints.view' },
            { routeName: 'service-feedback.categories.index', labelKey: 'nav.serviceFeedbackCategories', icon: TagsIcon, permission: 'service_feedback.manage_categories' },
            { routeName: 'service-feedback.questionnaire.index', labelKey: 'nav.serviceFeedbackQuestionnaire', icon: QuestionnaireIcon, permission: 'service_feedback.manage_questions' },
            { routeName: 'service-feedback.routing-rules.index', labelKey: 'nav.serviceFeedbackRoutingRules', icon: SignpostIcon, permission: 'service_feedback.manage_routing' },
            { routeName: 'service-feedback.organization-qr.index', labelKey: 'nav.organizationFeedbackQrCodes', icon: QrCodeIcon, permission: 'service_feedback.organization_qr.view' },
            { routeName: 'service-feedback.admin.reports', labelKey: 'nav.serviceFeedbackReports', icon: FileChartIcon, permission: 'service_feedback.view' },
            { routeName: 'service-feedback.submission-reports.index', labelKey: 'nav.serviceSubmissionReports', icon: FileSpreadsheetIcon, permission: 'service_feedback.view' },
            // PublicFeedbackSettings::canManage also requires citywide scope; the same permission serves scoped QR and position-service work.
            { routeName: 'service-feedback.settings.index', labelKey: 'nav.serviceFeedbackSettings', icon: SettingsIcon, permission: 'service_feedback.settings.manage', citywideOnly: true },
        ],
    },
    {
        key: 'cafeteria',
        labelKey: 'nav.groupCafeteria',
        icon: ChefHatIcon,
        items: [
            // Cafeteria Management (docs/cafeteria-policy-architecture.md): who operates,
            // where, who may eat where, on which terms, and what is owed.
            { routeName: 'cafeteria.dashboard', labelKey: 'nav.cafeteriaDashboard', icon: LayoutDashboard, permission: 'cafeteria_transactions.view' },
            { routeName: 'cafeteria.scan', labelKey: 'nav.cafeteriaScan', icon: QrCodeIcon,      permission: 'cafeteria_transactions.scan' },
            { routeName: 'cafeteria.payees.index', labelKey: 'cafeteriaPolicy.navProviders', icon: HandshakeIcon, permission: 'cafeteria_providers.viewAny' },
            { routeName: 'cafeteria.networks.index', labelKey: 'cafeteriaPolicy.navNetworks', icon: NetworkIcon, permission: 'cafeteria_networks.view' },
            { routeName: 'cafeteria.providers.index', labelKey: 'cafeteriaPolicy.navCafeterias', icon: CupHotIcon, permission: 'cafeteria_providers.viewAny' },
            { routeName: 'cafeteria.access.index', labelKey: 'cafeteriaPolicy.navAccess', icon: KeyIcon, permission: 'cafeteria_access.view' },
            { routeName: 'cafeteria.assignments.index', labelKey: 'cafeteriaPolicy.navAssignments', icon: UserPlusIcon, permission: 'cafeteria_assignments.view' },
            { routeName: 'cafeteria.employee-exclusions.index', labelKey: 'nav.cafeteriaEmployeeExclusions', icon: UserBlockIcon, permission: 'cafeteria_employee_exclusions.view' },
            { routeName: 'cafeteria.policies.index', labelKey: 'cafeteriaPolicy.navPolicies', icon: FileTextIcon, permission: 'cafeteria_policies.view' },
            { routeName: 'cafeteria.day-rules.index', labelKey: 'cafeteriaPolicy.navWorkingDays', icon: CalendarDateIcon, permission: 'cafeteria_day_rules.view' },
            { routeName: 'cafeteria.special-days.index', labelKey: 'nav.cafeteriaSpecialDays', icon: CalendarMarkIcon, permission: 'cafeteria_special_days.view' },
            { routeName: 'cafeteria.holidays.index', labelKey: 'cafeteriaPolicy.navHolidays', icon: ConfettiIcon, permission: 'public_holidays.viewAny' },
            { routeName: 'cafeteria.transactions.index', labelKey: 'nav.cafeteriaTransactions', icon: ReceiptTextIcon, permission: 'cafeteria_transactions.view' },
            { routeName: 'cafeteria.ledger.index', labelKey: 'nav.cafeteriaLedger', icon: WalletIcon,      permission: 'cafeteria_ledger.view' },
            { routeName: 'cafeteria.settlements.index', labelKey: 'cafeteriaPolicy.navSettlements', icon: HandMoneyIcon, permission: 'cafeteria_settlements.view' },
            { routeName: 'cafeteria.reports.index', labelKey: 'nav.cafeteriaReports', icon: FileChartIcon,    permission: 'cafeteria_reports.view' },
            { routeName: 'cafeteria.analytics.index', labelKey: 'cafeteriaPolicy.navAnalytics', icon: PieChartIcon, permission: 'cafeteria_reports.view' },
            { routeName: 'cafeteria.settings.index', labelKey: 'cafeteriaPolicy.navSystemDefaults', icon: SettingsIcon,    permission: 'cafeteria_settings.view' },
        ],
    },
    {
        key: 'transport',
        labelKey: 'nav.groupTransport',
        icon: BusIcon,
        items: [
            { routeName: 'transport.overview', labelKey: 'nav.transportOverview', icon: LayoutDashboard, permission: 'transport-reports.view' },
            { routeName: 'transport.scan', labelKey: 'nav.transportScan', icon: QrCodeIcon,      permission: 'transport-scan.create' },
            { routeName: 'transport.trips.index', labelKey: 'nav.transportTrips', icon: ActivityIcon, permission: 'transport-trips.viewAny' },
            { routeName: 'transport.transactions.index', labelKey: 'nav.transportTransactions', icon: ReceiptTextIcon, permission: 'transport-transactions.viewAny' },
            { routeName: 'transport.exceptions.index', labelKey: 'nav.transportExceptions', icon: ShieldWarningIcon, permission: 'transport-exceptions.viewAny' },
            { routeName: 'transport.providers.index', labelKey: 'nav.transportProviders', icon: HandshakeIcon, permission: 'transport-providers.viewAny' },
            { routeName: 'transport.routes.index', labelKey: 'nav.transportRoutes', icon: RouteIcon,      permission: 'transport-routes.viewAny' },
            { routeName: 'transport.schedules.index', labelKey: 'nav.transportSchedules', icon: CalendarIcon, permission: 'transport-schedules.viewAny' },
            { routeName: 'transport.vehicles.index', labelKey: 'nav.transportVehicles', icon: CarIcon,    permission: 'transport-vehicles.viewAny' },
            { routeName: 'transport.drivers.index', labelKey: 'nav.transportDrivers', icon: SteeringWheelIcon,        permission: 'transport-drivers.viewAny' },
            { routeName: 'transport.devices.index', labelKey: 'nav.transportDevices', icon: MonitorIcon, permission: 'transport-devices.viewAny' },
            { routeName: 'transport.passes.index', labelKey: 'nav.transportPasses', icon: TicketIcon,  permission: 'transport-passes.viewAny' },
            { routeName: 'transport.eligibility.index', labelKey: 'nav.transportEligibility', icon: BadgeCheckIcon, permission: 'transport-eligibility.viewAny' },
            { routeName: 'transport.contracts.index', labelKey: 'nav.transportContracts', icon: FileTextIcon, permission: 'transport-contracts.viewAny' },
            { routeName: 'transport.invoices.index', labelKey: 'nav.transportInvoices', icon: FileSpreadsheetIcon, permission: 'transport-invoices.viewAny' },
            { routeName: 'transport.settlements.index', labelKey: 'nav.transportSettlements', icon: HandMoneyIcon, permission: 'transport-settlements.viewAny' },
            { routeName: 'transport.reports.index', labelKey: 'nav.transportReports', icon: FileChartIcon, permission: 'transport-reports.view' },
            { routeName: 'transport.settings.index', labelKey: 'nav.transportSettings', icon: SettingsIcon,    permission: 'transport-settings.view' },
        ],
    },
    {
        /*
         * Everything that talks to a party outside the bureau: service
         * providers, their portal accounts, and the integration API.
         */
        key: 'providers',
        labelKey: 'nav.groupProviders',
        icon: HandshakeIcon,
        items: [
            { routeName: 'service-providers.index', labelKey: 'nav.providers',              icon: HandshakeIcon, permission: 'service-providers.viewAny' },
            { routeName: 'api-management.index', labelKey: 'nav.apiManagement', icon: CodeIcon, permission: 'api_management.view' },
        ],
    },
    {
        key: 'auditMonitoring',
        labelKey: 'nav.groupAuditMonitoring',
        icon: EyeScanIcon,
        items: [
            { routeName: 'audit-logs.index', labelKey: 'nav.auditLogs', icon: ClipboardClockIcon, permission: 'audit.view' },
        ],
    },
];

/** Messages, groups, channels and announcements: one entry for everyone who may use them. */

const dashboardNav: NavItem = {
    routeName: 'dashboard',
    labelKey: 'nav.dashboard',
    icon: LayoutDashboard,
};


/** Administration is split into labeled sub-clusters so unrelated concerns stay scannable. */
/**
 * Labeled sub-clusters for a long group, by route (brief: Organization Design
 * must not be twenty flat links). The group's `items` stay the single list
 * of pages and permissions; this only decides the headings they sit under.
 */
const GROUP_CLUSTERS: Record<string, { labelKey: string; routes: string[] }[]> = {
    organizationDesign: [
        { labelKey: 'nav.designClusterLegacy', routes: ['organization-design.legacy-baselines.index', 'organization-design.legal-sources.index', 'organization-design.imports.index'] },
        { labelKey: 'nav.designClusterChain', routes: ['organization-design.mandates.index', 'organization-design.functions.index', 'organization-design.services.index', 'organization-design.classifications.index', 'organization-design.processes.index'] },
        { labelKey: 'nav.designClusterPeople', routes: ['organization-design.professions.index', 'organization-design.competency-catalog.index'] },
        { labelKey: 'nav.designClusterStructure', routes: ['organization-design.studies.index', 'organization-design.responsibilities.index', 'organization-design.structure.index'] },
        { labelKey: 'nav.designClusterWorkforce', routes: ['organization-design.methodologies.index', 'organization-design.manpower-plans.index', 'organization-design.position-design.index'] },
        { labelKey: 'nav.designClusterQuality', routes: ['organization-design.reports.index', 'organization-design.mapping.index'] },
    ],
};

const adminGroups: { labelKey: string; items: NavItem[] }[] = [
    {
        labelKey: 'nav.adminAccess',
        items: [
            { routeName: 'users.index', labelKey: 'nav.users', icon: UserCircleIcon,   permission: 'users.viewAny' },
            { routeName: 'provider-users.index', labelKey: 'nav.cafeteriaProviderUsers', icon: ChefHatIcon, permission: 'cafeteria-provider-users.viewAny' },
            { routeName: 'roles.index', labelKey: 'nav.roles', icon: ShieldUserIcon,   permission: 'roles.viewAny' },
            { routeName: 'permissions.index', labelKey: 'nav.permissions', icon: LockIcon,       permission: 'permissions.viewAny' },
        ],
    },
    {
        labelKey: 'nav.adminSystem',
        items: [
            { routeName: 'code-rules.index', labelKey: 'nav.codeRules', icon: HashIcon, permission: 'code-rules.viewAny' },
            { routeName: 'recycle-bin.index', labelKey: 'nav.recycleBin', icon: TrashIcon,    permission: 'recycle-bin.view' },
            // API Management lives as a tab inside System Settings, beside
            // Security — not as a separate sidebar entry.
            { routeName: 'system-settings.index', labelKey: 'nav.systemSettings', icon: SettingsIcon, permission: 'system-settings.view' },
            { routeName: 'backups.index', labelKey: 'nav.backupRecovery', icon: DatabaseIcon, permission: 'backups.view_status' },
            { routeName: 'public-site-management.index', labelKey: 'publicSite.admin.title', icon: GlobalIcon, permission: 'public_site.view' },
        ],
    },
];

const SIDEBAR_GROUPS_STORAGE_KEY = 'euisis-sidebar-open-groups';

/**
 * A section without its own labelKey is a standalone category headed by its
 * single group's label; the group itself renders as the usual collapsible
 * button with its submenu.
 */
const sections: { labelKey?: string; keys: string[] }[] = [
    { labelKey: 'nav.sidebarPeople', keys: ['organization', 'hrMasterData', 'employeeManagement', 'identity', 'transferManagement', 'dailyActivity', 'fieldWork', 'performance', 'assessments', 'organizationDesign'] },
    { labelKey: 'nav.sidebarOperations', keys: ['situationRoom', 'intelligence', 'serviceManagement', 'serviceFeedback', 'providers', 'cafeteria', 'transport'] },
    { labelKey: 'nav.sidebarGovernance', keys: ['grievances', 'discipline', 'tribunalCourt', 'auditMonitoring'] },
    { keys: ['myWork'] },
];

interface Props {
    onClose?: () => void;
    collapsed?: boolean;
    onToggleCollapse?: () => void;
}

const focusRing = 'focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-(--sidebar-accent)';
const hoverSurface = 'hover:bg-(--sidebar-hover)';
const selectedSurface = 'bg-(--sidebar-accent-wash) text-(--sidebar-accent)';

/** Exact routes win; otherwise select the nearest permitted module (index or a page's own sub-routes). */
function activeRoute(items: NavSubItem[], current: string): string | undefined {
    if (items.some((item) => item.routeName === current)) return current;
    return items
        .map((item) => ({ name: item.routeName, prefix: item.routeName.replace(/\.(index|dashboard)$/, '') }))
        .filter((item) => current.startsWith(item.prefix + '.'))
        .sort((a, b) => b.prefix.length - a.prefix.length)[0]?.name;
}

export type NavLocation = { groupLabelKey: string; item: NavSubItem };

/**
 * Where a route sits in this navigation tree, found with the same nearest-module rule that highlights
 * the sidebar, so breadcrumbs and the selected item always agree. Null for home and for routes the
 * tree does not cover. Permissions are not applied here; callers decide what to link.
 */
export function navLocation(current: string, employeePortal: boolean, tab: string | null = null): NavLocation | null {
    const flatten = (items: NavItem[]) => items.flatMap((item) => item.children ?? [item]);
    const groups = employeePortal
        ? portalSections.flatMap((section) => section.groups.map((group) => ({ labelKey: group.labelKey, items: flatten(group.items) })))
        : [...navGroups, ...adminGroups].map((group) => ({ labelKey: group.labelKey, items: flatten(group.items) }));
    const home = employeePortal ? portalDashboard : dashboardNav;
    const match = activeRoute([home, ...groups.flatMap((group) => group.items)], current);
    if (!match || match === home.routeName) return null;
    for (const group of groups) {
        const candidates = group.items.filter((item) => item.routeName === match);
        const item = candidates.find((candidate) => (candidate.tab ?? null) === tab) ?? candidates[0];
        if (item) return { groupLabelKey: group.labelKey, item };
    }
    return null;
}

export default function AppSidebar({ onClose, collapsed = false, onToggleCollapse }: Props) {
    const { can } = useCan();
    const { locale, t } = useLocale();
    const { getString } = useSystemSettings();
    const { props: pageProps, url: pageUrl } = usePage();
    const isEmployeeUser = pageProps.is_employee_user === true;
    const hasEmployeeRecord = pageProps.has_employee_record === true;
    const authFlags = (pageProps as { auth?: { canViewDashboard?: boolean; unrestrictedScope?: boolean; isSuperAdmin?: boolean } }).auth ?? {};
    // Roles without dashboard access sign in to My Portal, so that is their home link too.
    const canViewDashboard = authFlags.canViewDashboard !== false;
    const citywide = authFlags.unrestrictedScope === true || authFlags.isSuperAdmin === true;
    const instanceId = useId();
    const searchRef = useRef<HTMLInputElement>(null);
    const navRef = useRef<HTMLElement>(null);
    const focusSearchAfterExpand = useRef(false);
    const [query, setQuery] = useState('');
    const normalizedQuery = query.trim().toLocaleLowerCase();

    const { brandName: appName, shortName } = useBranding();
    const orgName = locale === 'am'
        ? getString('id_cards.city_name_am', getString('general.organization_name', 'አዲስ አበባ ከተማ አስተዳደር'))
        : getString('id_cards.city_name_en', getString('general.organization_name', 'Addis Ababa City Administration'));
    const environmentLabel = getString('general.system_environment_label');
    const logoCentered = getString('appearance.logo_position', 'start') === 'center';

    // Modules outside the current production scope (config/features.php) are never listed.
    const features = (pageProps as { features?: Record<string, boolean> }).features ?? {};
    const moduleEnabled = (routeName: string) => !(
        (!features.transport && routeName.startsWith('transport.'))
        || (!features.court_cases && routeName.startsWith('court-cases.'))
    );
    // Keep the existing permission contract, including independently permitted child links.
    const permitted = (item: NavSubItem) => moduleEnabled(item.routeName)
        && (!item.employeeOnly || hasEmployeeRecord)
        && (!item.citywideOnly || citywide)
        && (!item.permission || can(item.permission))
        && (!item.anyPermission || item.anyPermission.some((permission) => can(permission)));
    const allowedItems = (items: NavItem[]): NavSubItem[] => items
        .filter(permitted)
        .flatMap((item) => item.children ? item.children.filter(permitted) : [item]);
    const visibleGroups = navGroups
        // "My Work" is self-service: an account with no employee record has
        // no activity of its own to register, whatever its permissions.
        .filter((group) => group.key !== 'myWork' || hasEmployeeRecord)
        .map((group) => ({ ...group, items: allowedItems(group.items) }))
        .filter((group) => group.items.length > 0);
    const visibleAdminGroups = adminGroups.map((group) => ({ ...group, items: allowedItems(group.items) }))
        .filter((group) => group.items.length > 0);
    const visibleAdminNav = visibleAdminGroups.flatMap((group) => group.items);
    const adminGroup: NavGroup = { key: 'admin', labelKey: 'nav.admin', icon: ShieldCheck, items: visibleAdminNav };
    const visiblePortalSections = portalSections
        .map((section) => ({
            ...section,
            groups: section.groups.map((group) => ({ ...group, items: allowedItems(group.items) })).filter((group) => group.items.length > 0),
        }))
        .filter((section) => section.groups.length > 0);
    // Employee-only accounts see My Portal; everyone else the admin structure.
    const allGroups = isEmployeeUser
        ? visiblePortalSections.flatMap((section) => section.groups)
        : [...visibleGroups, ...(visibleAdminNav.length ? [adminGroup] : [])];
    const homeNav = isEmployeeUser || !canViewDashboard ? portalDashboard : dashboardNav;
    const currentRoute = String(route().current() ?? '');
    const currentItem = activeRoute([homeNav, ...allGroups.flatMap((group) => group.items)], currentRoute);
    const activeGroupKeys = allGroups.filter((group) => group.items.some((item) => item.routeName === currentItem)).map((group) => group.key);
    const activeGroupSignature = activeGroupKeys.join(',');

    const [openGroups, setOpenGroups] = useState<Record<string, boolean>>(() => {
        try {
            const parsed: unknown = JSON.parse(window.localStorage.getItem(SIDEBAR_GROUPS_STORAGE_KEY) ?? '{}');
            if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                return Object.fromEntries(Object.entries(parsed).filter(([, value]) => typeof value === 'boolean'));
            }
        } catch { /* Storage is optional. */ }
        return {};
    });

    useEffect(() => {
        setOpenGroups((previous) => {
            const next = { ...previous };
            for (const key of activeGroupKeys) next[key] = true;
            return next;
        });
        setQuery('');
        // The stable signature avoids reopening a manually collapsed group on every render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pageUrl, activeGroupSignature]);

    // Deep items in long groups can sit below the fold; keep the current page visible after each visit.
    // The active group opens in a follow-up render and hidden items cannot scroll, so this also re-runs
    // once that group is open (but not when unrelated groups are toggled).
    const activeGroupOpen = activeGroupKeys.every((key) => openGroups[key] ?? isEmployeeUser);
    useEffect(() => {
        if (activeGroupOpen) navRef.current?.querySelector('[aria-current="page"]')?.scrollIntoView({ block: 'nearest' });
    }, [pageUrl, collapsed, activeGroupOpen]);

    useEffect(() => {
        try {
            window.localStorage.setItem(SIDEBAR_GROUPS_STORAGE_KEY, JSON.stringify(openGroups));
        } catch { /* Navigation remains usable without storage. */ }
    }, [openGroups]);

    useEffect(() => {
        if (collapsed) setQuery('');
        if (!collapsed && focusSearchAfterExpand.current) {
            focusSearchAfterExpand.current = false;
            searchRef.current?.focus();
        }
    }, [collapsed]);

    const matches = (item: NavSubItem) => t(item.labelKey).toLocaleLowerCase().includes(normalizedQuery);
    const matchingItems = (group: NavGroup) => !normalizedQuery || t(group.labelKey).toLocaleLowerCase().includes(normalizedQuery)
        ? group.items : group.items.filter(matches);
    const matchingGroups = allGroups.filter((group) => matchingItems(group).length > 0);

    // "/" jumps to the menu filter (desktop sidebar only; ignored while typing elsewhere).
    useEffect(() => {
        if (!onToggleCollapse) return;
        function onKey(event: KeyboardEvent) {
            if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) return;
            const target = event.target as HTMLElement | null;
            if (target && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) return;
            if (!navRef.current?.offsetParent) return;
            event.preventDefault();
            if (collapsed) {
                focusSearchAfterExpand.current = true;
                onToggleCollapse?.();
            } else {
                searchRef.current?.focus();
            }
        }
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [collapsed, onToggleCollapse]);

    /** Marks the part of a label that matched the filter, so it is clear why an item is listed. */
    function highlight(text: string): ReactNode {
        const index = normalizedQuery ? text.toLocaleLowerCase().indexOf(normalizedQuery) : -1;
        if (index < 0) return text;
        return <>{text.slice(0, index)}<mark className="rounded-xs bg-(--sidebar-accent-wash) font-semibold text-(--sidebar-fg)">{text.slice(index, index + normalizedQuery.length)}</mark>{text.slice(index + normalizedQuery.length)}</>;
    }

    /**
     * nested: a page inside a group (quieter, with a smaller icon). compact: the icon-only collapsed rail.
     * onNavigate: closes a collapsed-rail flyout after choosing a page.
     */
    function renderLink(item: NavSubItem, { nested = false, compact = collapsed, onNavigate }: { nested?: boolean; compact?: boolean; onNavigate?: () => void } = {}) {
        const selected = currentItem === item.routeName && (!item.tab
            || new URLSearchParams(pageUrl.split('?')[1] ?? '').get('tab') === item.tab);
        const Icon = item.icon;
        const label = t(item.labelKey);
        return (
            <li key={item.routeName + (item.tab ?? '')}>
                <Link
                    href={item.tab ? route(item.routeName) + '?tab=' + item.tab : route(item.routeName)}
                    onClick={() => { onClose?.(); onNavigate?.(); }}
                    aria-current={selected ? 'page' : undefined}
                    aria-label={compact ? label : undefined}
                    title={compact ? label : undefined}
                    className={[
                        // scroll-my keeps an auto-scrolled current page clear of the list's fading edges.
                        'relative flex scroll-my-12 items-center gap-2.5 rounded-md text-sm transition-colors duration-150',
                        focusRing, hoverSurface,
                        compact ? 'mx-auto h-10 w-10 justify-center' : nested ? 'min-h-8 px-2.5 py-1.5' : 'min-h-9 px-2.5 py-2',
                        selected ? selectedSurface + ' font-semibold'
                            : nested ? 'text-(--sidebar-muted) hover:text-(--sidebar-fg)' : 'font-medium text-(--sidebar-fg)',
                    ].join(' ')}
                >
                    {selected && <span aria-hidden="true" className="absolute inset-y-1.5 inset-s-0 w-0.5 rounded-full bg-(--sidebar-accent)" />}
                    <Icon aria-hidden="true" className={nested && !compact
                        ? ['h-4 w-4 shrink-0', selected ? '' : 'opacity-80'].join(' ')
                        : 'h-[18px] w-[18px] shrink-0'} />
                    {!compact && <span className="min-w-0 flex-1 whitespace-normal wrap-break-word leading-snug">{highlight(label)}</span>}
                    {item.badge ? (
                        <span
                            aria-label={t('nav.unreadCount').replace(':n', String(item.badge))}
                            className={compact
                                ? 'absolute inset-e-1 top-1 h-2.5 w-2.5 rounded-full bg-accent ring-2 ring-(--sidebar-bg,transparent)'
                                : 'inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-accent px-1.5 text-[11px] font-semibold tabular-nums text-white'}
                        >
                            {compact ? null : item.badge > 99 ? '99+' : item.badge}
                        </span>
                    ) : null}
                </Link>
            </li>
        );
    }

    /** A group's pages; Administration and Organization Design keep labeled sub-clusters. */
    function renderGroupItems(group: NavGroup, items: NavSubItem[], onNavigate?: () => void) {
        const link = (item: NavSubItem) => renderLink(item, { nested: true, compact: false, onNavigate });
        const clusters = GROUP_CLUSTERS[group.key];
        if (clusters) {
            // Pages outside every cluster (the module overview) lead, unlabeled.
            const clustered = new Set(clusters.flatMap((c) => c.routes));
            const lead = items.filter((item) => !clustered.has(item.routeName));
            return (
                <>
                    {lead.length > 0 && <ul className="space-y-px">{lead.map(link)}</ul>}
                    {clusters.map((cluster) => {
                        const clusterItems = items.filter((item) => cluster.routes.includes(item.routeName));
                        return clusterItems.length > 0 && (
                            <div key={cluster.labelKey} className="py-1">
                                <p className="px-3 pb-1 pt-1.5 text-[11px] font-semibold text-(--sidebar-muted)">{t(cluster.labelKey)}</p>
                                <ul className="space-y-px">{clusterItems.map(link)}</ul>
                            </div>
                        );
                    })}
                </>
            );
        }
        if (group.key !== 'admin') return <ul className="space-y-px">{items.map(link)}</ul>;
        return visibleAdminGroups.map((subgroup) => {
            const subItems = subgroup.items.filter((item) => items.some((match) => match.routeName === item.routeName));
            return subItems.length > 0 && (
                <div key={subgroup.labelKey} className="py-1 first:pt-0">
                    <p className="px-3 pb-1 pt-1.5 text-[11px] font-semibold text-(--sidebar-muted)">{t(subgroup.labelKey)}</p>
                    <ul className="space-y-px">{subItems.map(link)}</ul>
                </div>
            );
        });
    }

    function renderSection(labelKey: string, groups: NavGroup[]) {
        if (!groups.some((group) => matchingItems(group).length)) return null;
        return <div key={labelKey} className="mb-4 last:mb-0">
            {collapsed
                ? <div className="mx-3 my-2 border-t border-(--sidebar-border)" />
                : <p className="px-2.5 pb-1.5 pt-1 text-[11px] font-semibold uppercase leading-relaxed tracking-[0.08em] text-(--sidebar-muted)">{t(labelKey)}</p>}
            <div className="space-y-0.5">{groups.map(renderGroup)}</div>
        </div>;
    }

    function renderGroup(group: NavGroup) {
        const items = matchingItems(group);
        if (!items.length) return null;
        const Icon = group.icon;
        const selected = activeGroupKeys.includes(group.key);
        const isOpen = Boolean(normalizedQuery || (openGroups[group.key] ?? isEmployeeUser));
        const panelId = instanceId + '-group-' + group.key;
        const label = t(group.labelKey);
        if (group.items.length === 1 && group.key !== 'admin') {
            return <ul key={group.key}>{renderLink(group.items[0])}</ul>;
        }
        if (collapsed) {
            // The rail stays compact: a group opens as a flyout of its pages instead of expanding the sidebar.
            return (
                <Popover key={group.key}>
                    <PopoverButton title={label} aria-label={label}
                        className={['mx-auto flex h-10 w-10 items-center justify-center rounded-md transition-colors data-open:bg-(--sidebar-hover)', focusRing, hoverSurface,
                            selected ? selectedSurface : 'text-(--sidebar-fg)'].join(' ')}>
                        <Icon className="h-[18px] w-[18px] shrink-0" aria-hidden="true" />
                    </PopoverButton>
                    <PopoverPanel anchor="right start" transition
                        className="z-50 w-64 rounded-lg border border-(--sidebar-border) bg-(--sidebar-bg) p-2 text-(--sidebar-fg) shadow-xl outline-hidden transition duration-100 ease-out [--anchor-gap:12px] [--anchor-padding:8px] data-closed:-translate-x-1 data-closed:opacity-0">
                        {({ close }) => (
                            <>
                                <p className="px-2.5 pb-1.5 pt-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-(--sidebar-muted)">{label}</p>
                                {renderGroupItems(group, items, close)}
                            </>
                        )}
                    </PopoverPanel>
                </Popover>
            );
        }
        return (
            <div key={group.key}>
                <button
                    type="button"
                    aria-expanded={isOpen}
                    aria-controls={panelId}
                    onClick={() => { if (!normalizedQuery) setOpenGroups((previous) => ({ ...previous, [group.key]: !isOpen })); }}
                    className={[
                        'flex min-h-9 w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-sm text-(--sidebar-fg) transition-colors duration-150',
                        focusRing, hoverSurface,
                        // Only the page itself carries the selected surface; its group is marked by weight and an accent icon.
                        selected ? 'font-semibold' : 'font-medium',
                    ].join(' ')}
                >
                    <Icon className={['h-[18px] w-[18px] shrink-0', selected ? 'text-(--sidebar-accent)' : ''].join(' ')} aria-hidden="true" />
                    <span className="min-w-0 flex-1 whitespace-normal wrap-break-word leading-snug">{highlight(label)}</span>
                    <ChevronDown className={['h-3.5 w-3.5 shrink-0 text-(--sidebar-muted) transition-transform', isOpen ? '' : '-rotate-90'].join(' ')} aria-hidden="true" />
                </button>
                <div id={panelId} hidden={!isOpen}>
                    <div className="ms-[19px] mt-0.5 mb-1.5 border-s border-(--sidebar-border) ps-2">
                        {renderGroupItems(group, items)}
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div data-sidebar className="flex h-full min-h-0 w-full flex-col border-e border-(--sidebar-border) bg-(--sidebar-bg) text-(--sidebar-fg)">
            <div className={collapsed ? 'flex h-16 shrink-0 items-center justify-center border-b border-(--sidebar-border)' : 'app-dot-grid shrink-0 border-b border-(--sidebar-border) px-4 py-3.5'}>
                <div className="flex items-start gap-2">
                    <Link
                        href={route(homeNav.routeName)}
                        onClick={onClose}
                        aria-label={appName}
                        title={collapsed ? appName : undefined}
                        className={['flex min-w-0 flex-1 gap-3 rounded-md', focusRing, logoCentered && !collapsed ? 'flex-col items-center text-center' : 'items-center'].join(' ')}
                    >
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-(--sidebar-bg) p-1 ring-1 ring-(--sidebar-border)">
                            <ApplicationLogo className="h-full w-full object-contain" />
                        </span>
                        {!collapsed && <div className="min-w-0">
                            <p className="whitespace-normal wrap-break-word text-[15px] font-medium leading-snug">{appName}</p>
                            <p className="whitespace-normal wrap-break-word text-[11px] font-semibold uppercase tracking-wide text-(--sidebar-muted)">{shortName}</p>
                            <p className="mt-1 whitespace-normal wrap-break-word text-xs leading-relaxed text-(--sidebar-muted)">{orgName}</p>
                        </div>}
                    </Link>
                    {onClose && <button type="button" onClick={onClose} aria-label={t('nav.closeMenu')} className={['flex h-9 w-9 shrink-0 items-center justify-center rounded-md', focusRing, hoverSurface].join(' ')}><X className="h-[18px] w-[18px]" aria-hidden="true" /></button>}
                </div>
            </div>

            <div className={collapsed ? 'px-2 pt-3' : 'px-3 pt-3'}>
                {collapsed ? <button
                    type="button"
                    aria-label={t('nav.filterMenu')}
                    title={t('nav.filterMenu')}
                    onClick={() => { focusSearchAfterExpand.current = true; onToggleCollapse?.(); }}
                    className={['mx-auto flex h-10 w-10 items-center justify-center rounded-md text-(--sidebar-muted)', focusRing, hoverSurface].join(' ')}
                ><SearchIcon className="h-[18px] w-[18px]" aria-hidden="true" /></button> : (
                    <div className="flex items-center gap-2 rounded-md border border-(--sidebar-border) bg-(--sidebar-hover) px-2.5 transition-colors focus-within:border-(--sidebar-accent) focus-within:ring-2 focus-within:ring-(--sidebar-accent-wash)">
                        <SearchIcon className="h-4 w-4 shrink-0 text-(--sidebar-muted)" aria-hidden="true" />
                        <input
                            ref={searchRef}
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            onKeyDown={(event) => { if (event.key === 'Escape' && query) { event.preventDefault(); event.stopPropagation(); setQuery(''); } }}
                            aria-label={t('nav.filterMenu')}
                            placeholder={t('nav.filterMenu')}
                            aria-keyshortcuts={onToggleCollapse ? '/' : undefined}
                            className="peer h-9 w-full min-w-0 border-0 bg-transparent p-0 text-sm text-(--sidebar-fg) placeholder:text-(--sidebar-muted) focus:ring-0"
                        />
                        {onToggleCollapse && !query && <kbd aria-hidden="true" className="shrink-0 rounded-sm border border-(--sidebar-border) px-1.5 font-sans text-[10px] font-medium leading-4 text-(--sidebar-muted) peer-focus:hidden">/</kbd>}
                    </div>
                )}
            </div>

            <nav ref={navRef} className="sidebar-scroll min-h-0 flex-1 overflow-y-auto overscroll-contain px-2 pb-6 pt-3 [-webkit-mask-image:linear-gradient(to_bottom,transparent,#000_12px,#000_94%,transparent)] mask-[linear-gradient(to_bottom,transparent,#000_12px,#000_94%,transparent)]" aria-label={t('publicSite.mainNavigation')}>
                {(!normalizedQuery || matches(homeNav)) && (
                    <ul className="mb-4 space-y-px">
                        {renderLink(homeNav)}
                    </ul>
                )}
                {isEmployeeUser
                    ? visiblePortalSections.map((section) => renderSection(section.labelKey, section.groups))
                    : sections.map((section) => {
                        const groups = section.keys.flatMap((key) => visibleGroups.filter((group) => group.key === key));
                        if (section.labelKey === undefined) return groups[0] ? renderSection(groups[0].labelKey, groups) : null;
                        if (section.labelKey === 'nav.sidebarGovernance' && visibleAdminNav.length) groups.push(adminGroup);
                        return renderSection(section.labelKey, groups);
                    })}
                {normalizedQuery && !matchingGroups.length && !matches(homeNav) && (
                    <p role="status" className="px-3 py-5 text-sm leading-relaxed text-(--sidebar-muted)">{t('nav.noResults')}</p>
                )}
            </nav>

            <div className="shrink-0 border-t border-(--sidebar-border) p-2">
                {onToggleCollapse ? (
                    <button type="button" onClick={onToggleCollapse} aria-label={t(collapsed ? 'nav.expandSidebar' : 'nav.collapseSidebar')} title={collapsed ? t('nav.expandSidebar') : undefined}
                        className={['flex min-h-10 items-center gap-3 rounded-md text-sm text-(--sidebar-muted)', focusRing, hoverSurface, collapsed ? 'mx-auto w-10 justify-center' : 'w-full px-2.5'].join(' ')}>
                        <ChevronRight className={collapsed ? 'h-4 w-4' : 'h-4 w-4 rotate-180'} aria-hidden="true" />
                        {!collapsed && <span>{t('nav.collapseSidebar')}</span>}
                    </button>
                ) : <p className="px-3 py-2 text-xs text-(--sidebar-muted)">{t(isEmployeeUser ? 'nav.myPortal' : 'nav.admin')}</p>}
                {/* Non-production environments are flagged where every page shows it, so test data is never mistaken for live. */}
                {environmentLabel && environmentLabel.toLowerCase() !== 'production' && (collapsed
                    ? <span title={environmentLabel} aria-label={environmentLabel} className="mx-auto mb-1 mt-1 block h-2 w-2 rounded-full bg-amber-400" />
                    : <p className="px-3 pb-1 pt-1"><span className="inline-flex items-center gap-1.5 rounded-full bg-amber-400/15 px-2 py-0.5 text-[11px] font-semibold text-amber-600 ring-1 ring-inset ring-amber-400/40 dark:text-amber-300">
                        <span className="h-1.5 w-1.5 rounded-full bg-amber-400" aria-hidden="true" />{environmentLabel}
                    </span></p>)}
            </div>
        </div>
    );
}
