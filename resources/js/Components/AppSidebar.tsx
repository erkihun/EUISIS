import { Link, usePage } from '@inertiajs/react';
import {
    LayoutDashboard,
    Building2,
    Users,
    CreditCard,
    Layers,
    ScrollText,
    X,
    SettingsIcon,
    ShieldCheck,
    Briefcase,
    TrashIcon,
    TagsIcon,
    GitBranchIcon,
    HashIcon,
    GitForkIcon,
    ArrowLeftRightIcon,
    ClipboardCheckIcon,
    ClipboardListIcon,
    MegaphoneIcon,
    Inbox,
    BadgeCheckIcon,
    ReceiptTextIcon,
    HandshakeIcon,
    KeyIcon,
    UserCogIcon,
    TrendingUpIcon,
    HardHatIcon,
    ActivityIcon,
    BoxesIcon,
    QrCodeIcon,
    UserIcon,
    NetworkIcon,
    MessageSquareIcon,
    StarIcon,
} from '@/Components/Icons';
import { CSSProperties, SVGProps, useMemo, useState } from 'react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import { useCan } from '@/hooks/useCan';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';

/** A leaf nav item — renders as a clickable link. */
type NavSubItem = {
    routeName: string;
    labelKey: string;
    icon: (p: SVGProps<SVGSVGElement>) => JSX.Element;
    permission?: string;
    tab?: string;
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
        key: 'organization',
        labelKey: 'nav.groupOrganization',
        icon: Building2,
        items: [
            { routeName: 'organizations.index',           labelKey: 'nav.organizations',         icon: Building2,     permission: 'organizations.view' },
            { routeName: 'organization-types.index',      labelKey: 'nav.organizationTypes',     icon: TagsIcon,      permission: 'organization-types.viewAny' },
            { routeName: 'organization-units.index',      labelKey: 'nav.organizationUnits',     icon: GitBranchIcon, permission: 'organization-units.viewAny' },
            { routeName: 'organization-unit-types.index', labelKey: 'nav.organizationUnitTypes', icon: BoxesIcon,     permission: 'organization-unit-types.viewAny' },
            { routeName: 'hierarchy-versions.index',      labelKey: 'nav.hierarchyVersions',     icon: GitForkIcon,   permission: 'hierarchy-versions.viewAny' },
            /*
             * The controller also accepts `functional-reporting.viewReports`;
             * only one permission can gate a nav entry, so the broader of the
             * two is used and the page re-checks both on arrival.
             */
            { routeName: 'reporting-lines.index',         labelKey: 'nav.reportingLines',        icon: NetworkIcon,   permission: 'relationships.viewAny' },
            { routeName: 'code-rules.index',              labelKey: 'nav.codeRules',             icon: HashIcon,      permission: 'code-rules.viewAny' },
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
        icon: Briefcase,
        items: [
            { routeName: 'positions.index',               labelKey: 'nav.positions',              icon: Briefcase,          permission: 'positions.viewAny' },
            { routeName: 'positions.status',              labelKey: 'nav.newJobPositionsStatus',  icon: ClipboardCheckIcon, permission: 'positions.viewAny' },
            { routeName: 'position-establishments.index', labelKey: 'nav.positionEstablishments', icon: ClipboardListIcon,  permission: 'position-establishments.viewAny' },
            { routeName: 'position-services.index',       labelKey: 'nav.positionServices',       icon: Layers,             permission: 'service_feedback.settings.manage' },
            { routeName: 'grade-levels.index',            labelKey: 'nav.gradeLevels',            icon: TrendingUpIcon,     permission: 'grade-levels.viewAny' },
            { routeName: 'occupations.index',             labelKey: 'nav.occupations',            icon: HardHatIcon,        permission: 'occupations.viewAny' },
            { routeName: 'isic-activities.index',         labelKey: 'nav.isicActivities',         icon: ActivityIcon,       permission: 'isic-activities.viewAny' },
        ],
    },
    {
        key: 'employeeManagement',
        labelKey: 'nav.groupEmployeeManagement',
        icon: Users,
        items: [
            { routeName: 'employees.index',                      labelKey: 'nav.employees',             icon: Users,              permission: 'employees.view' },
            { routeName: 'vacancy-announcements.index',          labelKey: 'nav.vacancyAnnouncements',  icon: MegaphoneIcon,      permission: 'vacancy-announcements.viewAny' },
            { routeName: 'vacancy-applications.my-applications', labelKey: 'nav.myApplications',        icon: Inbox },
            { routeName: 'transfers.dashboard',                  labelKey: 'nav.transferDashboard',     icon: ArrowLeftRightIcon, permission: 'transfers.view' },
            { routeName: 'transfer-announcements.index',         labelKey: 'nav.transferAnnouncements', icon: MegaphoneIcon,      permission: 'transfers.announcements.view' },
            { routeName: 'transfer-applications.index',          labelKey: 'nav.transferApplications',  icon: Inbox,              permission: 'transfers.applications.view' },
            { routeName: 'transfer-settings.show',               labelKey: 'nav.transferSettings',      icon: SettingsIcon,       permission: 'transfers.settings.manage' },
        ],
    },
    {
        key: 'identity',
        labelKey: 'nav.groupIdentity',
        icon: CreditCard,
        items: [
            { routeName: 'id-cards.index',      labelKey: 'nav.idCards',      icon: CreditCard,         permission: 'cards.view' },
            { routeName: 'card-requests.index', labelKey: 'nav.cardRequests', icon: ClipboardCheckIcon, permission: 'card-requests.viewAny' },
        ],
    },
    {
        key: 'grievances',
        labelKey: 'nav.groupGrievances',
        icon: ScrollText,
        items: [
            { routeName: 'grievances.index',           labelKey: 'nav.grievances',          icon: ClipboardListIcon, permission: 'grievances.manage' },
            { routeName: 'grievances.my',              labelKey: 'nav.myGrievances',        icon: Inbox },
            { routeName: 'grievance-committees.index', labelKey: 'nav.grievanceCommittees', icon: Users,             permission: 'grievances.manage' },
            { routeName: 'grievance-categories.index', labelKey: 'nav.grievanceCategories', icon: TagsIcon,          permission: 'grievances.manage' },
            { routeName: 'grievance-sla-rules.index',  labelKey: 'nav.grievanceSlaRules',   icon: SettingsIcon,      permission: 'grievances.manage' },
            { routeName: 'tribunal-cases.index',       labelKey: 'nav.tribunalCases',       icon: ShieldCheck,       permission: 'grievances.tribunal' },
        ],
    },
    {
        /*
         * Client-facing service delivery: what a position offers, and what
         * citizens said about it. The entitlements catalog sits here too, as
         * the other half of what "service" means in this system.
         */
        key: 'serviceManagement',
        labelKey: 'nav.groupServiceManagement',
        icon: MessageSquareIcon,
        items: [
            { routeName: 'service-feedback.admin.dashboard', labelKey: 'nav.serviceFeedbackDashboard', icon: LayoutDashboard,   permission: 'service_feedback.view' },
            { routeName: 'service-feedback.admin.index',     labelKey: 'nav.serviceFeedbackList',      icon: MessageSquareIcon, permission: 'service_feedback.view' },
            { routeName: 'service-feedback.admin.reports',   labelKey: 'nav.serviceFeedbackReports',   icon: StarIcon,          permission: 'service_feedback.view' },
            { routeName: 'service-types.index',              labelKey: 'nav.serviceTypes',             icon: Layers,            permission: 'service-types.viewAny' },
            { routeName: 'entitlements.index',               labelKey: 'nav.entitlements',             icon: BadgeCheckIcon },
            { routeName: 'entitlement-rules.index',          labelKey: 'nav.entitlementRules',         icon: ReceiptTextIcon,   permission: 'entitlement-rules.viewAny' },
        ],
    },
    {
        key: 'cafeteria',
        labelKey: 'nav.groupCafeteria',
        icon: QrCodeIcon,
        items: [
            { routeName: 'cafeteria.dashboard',          labelKey: 'nav.cafeteriaDashboard',    icon: LayoutDashboard, permission: 'cafeteria_transactions.viewAny' },
            { routeName: 'cafeteria.scan',               labelKey: 'nav.cafeteriaScan',         icon: QrCodeIcon,      permission: 'cafeteria_transactions.scan' },
            { routeName: 'cafeteria.transactions.index', labelKey: 'nav.cafeteriaTransactions', icon: ReceiptTextIcon, permission: 'cafeteria_transactions.viewAny' },
            { routeName: 'cafeteria.ledger.index',       labelKey: 'nav.cafeteriaLedger',       icon: ScrollText,      permission: 'cafeteria_ledger.viewAny' },
            { routeName: 'cafeteria.reports.index',      labelKey: 'nav.cafeteriaReports',      icon: ActivityIcon,    permission: 'cafeteria_reports.viewAny' },
            { routeName: 'cafeteria.providers.index',    labelKey: 'nav.cafeteriaProviders',    icon: HandshakeIcon,   permission: 'cafeteria_providers.viewAny' },
            { routeName: 'cafeteria.settings.index',     labelKey: 'nav.cafeteriaSettings',     icon: SettingsIcon,    permission: 'cafeteria_settings.view' },
        ],
    },
    {
        key: 'transport',
        labelKey: 'nav.groupTransport',
        icon: ActivityIcon,
        items: [
            { routeName: 'transport.providers.index', labelKey: 'nav.transportProviders', icon: HandshakeIcon,   permission: 'transport-providers.viewAny' },
            { routeName: 'transport.scan',            labelKey: 'nav.transportScan',      icon: QrCodeIcon,      permission: 'transport-passes.viewAny' },
            { routeName: 'transport.routes.index',    labelKey: 'nav.transportRoutes',    icon: ScrollText,      permission: 'transport-routes.viewAny' },
            { routeName: 'transport.vehicles.index',  labelKey: 'nav.transportVehicles',  icon: ActivityIcon,    permission: 'transport-vehicles.viewAny' },
            { routeName: 'transport.drivers.index',   labelKey: 'nav.transportDrivers',   icon: UserIcon,        permission: 'transport-drivers.viewAny' },
            { routeName: 'transport.passes.index',    labelKey: 'nav.transportPasses',    icon: BadgeCheckIcon,  permission: 'transport-passes.viewAny' },
            { routeName: 'transport.reports.index',   labelKey: 'nav.transportReports',   icon: ReceiptTextIcon, permission: 'transport-reports.view' },
            { routeName: 'transport.settings.index',  labelKey: 'nav.transportSettings',  icon: SettingsIcon,    permission: 'transport-settings.view' },
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
            { routeName: 'service-providers.index', labelKey: 'nav.providers',              icon: HandshakeIcon },
            { routeName: 'provider-users.index',    labelKey: 'nav.cafeteriaProviderUsers', icon: UserCogIcon, permission: 'cafeteria-provider-users.viewAny' },
            { routeName: 'api-management.index',    labelKey: 'nav.apiManagement',          icon: NetworkIcon, permission: 'api_management.view' },
        ],
    },
    {
        key: 'auditMonitoring',
        labelKey: 'nav.groupAuditMonitoring',
        icon: ScrollText,
        items: [
            { routeName: 'audit-logs.index', labelKey: 'nav.auditLogs', icon: ScrollText, permission: 'audit.view' },
        ],
    },
];

const dashboardNav: NavItem = {
    routeName: 'dashboard',
    labelKey: 'nav.dashboard',
    icon: LayoutDashboard,
};

const employeeNav: NavItem[] = [
    { routeName: 'employee.portal',               labelKey: 'nav.myPortal',             icon: UserIcon },
    { routeName: 'employee.entitlements',         labelKey: 'nav.myEntitlements',       icon: BadgeCheckIcon },
    { routeName: 'employee.transfer-applications', labelKey: 'nav.transferApplications', icon: Inbox },
    { routeName: 'public.transfer-announcements', labelKey: 'nav.announcements',         icon: MegaphoneIcon },
];

/** Administration is split into labeled sub-clusters so 6 unrelated concerns stay scannable. */
const adminGroups: { labelKey: string; items: NavItem[] }[] = [
    {
        labelKey: 'nav.adminAccess',
        items: [
            { routeName: 'users.index',          labelKey: 'nav.users',                  icon: UserCogIcon,   permission: 'users.viewAny' },
            { routeName: 'provider-users.index', labelKey: 'nav.cafeteriaProviderUsers', icon: HandshakeIcon, permission: 'cafeteria-provider-users.viewAny' },
            { routeName: 'roles.index',          labelKey: 'nav.roles',                  icon: ShieldCheck,   permission: 'roles.viewAny' },
            { routeName: 'permissions.index',    labelKey: 'nav.permissions',            icon: KeyIcon,       permission: 'permissions.viewAny' },
        ],
    },
    {
        labelKey: 'nav.adminSystem',
        items: [
            { routeName: 'recycle-bin.index',     labelKey: 'nav.recycleBin',     icon: TrashIcon,    permission: 'recycle-bin.view' },
            // API Management lives as a tab inside System Settings, beside
            // Security — not as a separate sidebar entry.
            { routeName: 'system-settings.index', labelKey: 'nav.systemSettings', icon: SettingsIcon, permission: 'system-settings.view' },
        ],
    },
];

/** Flattened admin items — used for active-state detection and collapsed icon rail. */
const adminNav: NavItem[] = adminGroups.flatMap((g) => g.items);

interface Props {
    onClose?: () => void;
    collapsed?: boolean;
    onToggleCollapse?: () => void;
}

function ChevronLeft({ className }: { className?: string }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" />
        </svg>
    );
}
function ChevronRight({ className }: { className?: string }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
        </svg>
    );
}
function ChevronDown({ className }: { className?: string }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
        </svg>
    );
}

/** Single nav item — expanded or icon-only collapsed */
function NavLink({ item, collapsed, isAdmin = false, index = 0 }: { item: NavItem; collapsed: boolean; isAdmin?: boolean; index?: number }) {
    const { can } = useCan();
    const { t } = useLocale();
    const { url: pageUrl } = usePage();

    if (item.permission && !can(item.permission)) return null;

    const href = item.tab ? `${route(item.routeName)}?tab=${item.tab}` : route(item.routeName);
    const isActive = item.tab
        ? route().current(item.routeName) && (() => {
            const currentTab = new URLSearchParams(pageUrl.split('?')[1] ?? '').get('tab') ?? 'general';
            return currentTab === item.tab;
        })()
        : route().current(item.routeName);
    const Icon = item.icon;
    const label = t(item.labelKey);

    const activeBar = 'border-[var(--color-primary)] bg-[color:var(--color-primary)]/10';
    const activeText = 'text-[color:var(--color-primary)]';
    const activeIcon  = activeText;
    const hoverBg = 'hover:bg-[color:var(--color-primary)]/10 hover:text-gray-900 dark:hover:text-slate-100';

    if (collapsed) {
        return (
            <li>
                <Link
                    href={href}
                    title={label}
                    aria-label={label}
                    aria-current={isActive ? 'page' : undefined}
                    className={[
                        'sidebar-press group relative mx-2 flex h-10 w-10 items-center justify-center rounded-lg',
                        'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]',
                        'hover:scale-[1.06]',
                        isActive
                            ? `${activeBar} ${activeText}`
                            : `text-gray-500 dark:text-slate-500 ${hoverBg}`,
                    ].join(' ')}
                >
                    <Icon
                        className={[
                            'h-5 w-5 shrink-0 transition-colors',
                            isActive ? activeIcon : 'text-gray-500 group-hover:text-gray-800 dark:text-slate-500 dark:group-hover:text-slate-300',
                        ].join(' ')}
                        aria-hidden="true"
                    />
                </Link>
            </li>
        );
    }

    return (
        <li className="sidebar-item" style={{ '--i': index } as CSSProperties}>
            <Link
                href={href}
                aria-current={isActive ? 'page' : undefined}
                className={[
                    'sidebar-press group relative flex items-center gap-3 overflow-hidden rounded-lg py-2.5 pl-3 pr-3 text-[15px] font-normal',
                    'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]',
                    isActive
                        ? `${activeBar} ${activeText}`
                        : `text-gray-600 dark:text-slate-400 ${hoverBg}`,
                ].join(' ')}
            >
                {isActive && (
                    <span
                        aria-hidden="true"
                        className="sidebar-active-bar absolute inset-y-1.5 left-0 w-0.5 rounded-full bg-[var(--color-primary)]"
                    />
                )}
                <Icon
                    className={[
                        'h-[19px] w-[19px] shrink-0 transition-all duration-200 group-hover:scale-110',
                        isActive ? activeIcon : 'text-gray-500 group-hover:text-gray-800 dark:text-slate-500 dark:group-hover:text-slate-300',
                    ].join(' ')}
                    aria-hidden="true"
                />
                <span className="truncate transition-transform duration-200 group-hover:translate-x-0.5">{label}</span>
            </Link>
        </li>
    );
}

/** Nav item that has expandable sub-items (dropdown within a group). */
function NavItemDropdown({
    item,
    collapsed,
    index = 0,
}: {
    item: NavItem & { children: NavSubItem[] };
    collapsed: boolean;
    index?: number;
}) {
    const { can } = useCan();
    const { t } = useLocale();

    const visibleChildren = item.children.filter((c) => !c.permission || can(c.permission));
    if (visibleChildren.length === 0) return null;

    const anyChildActive = visibleChildren.some((c) => route().current(c.routeName));
    const [open, setOpen] = useState(anyChildActive);

    const activeBar  = 'border-[var(--color-primary)] bg-[color:var(--color-primary)]/10';
    const activeText = 'text-[color:var(--color-primary)]';
    const hoverBg    = 'hover:bg-[color:var(--color-primary)]/10 hover:text-gray-900 dark:hover:text-slate-100';
    const Icon       = item.icon;
    const label      = t(item.labelKey);

    if (collapsed) {
        // In collapsed mode render the parent icon only — clicking opens sub-items via tooltip/hover isn't supported;
        // just show a single icon that links to the dashboard route.
        return (
            <li>
                <button
                    type="button"
                    title={label}
                    aria-label={label}
                    onClick={() => setOpen((o) => !o)}
                    className={[
                        'sidebar-press group relative mx-2 flex h-10 w-10 items-center justify-center rounded-lg hover:scale-[1.06]',
                        'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]',
                        anyChildActive
                            ? `${activeBar} ${activeText}`
                            : `text-gray-500 dark:text-slate-500 ${hoverBg}`,
                    ].join(' ')}
                >
                    <Icon className="h-5 w-5 shrink-0" aria-hidden="true" />
                </button>
            </li>
        );
    }

    return (
        <li className="sidebar-item" style={{ '--i': index } as CSSProperties}>
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                aria-expanded={open}
                className={[
                    'sidebar-press group relative flex w-full items-center gap-3 overflow-hidden rounded-lg py-2.5 pl-3 pr-3 text-[15px] font-normal',
                    'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]',
                    anyChildActive
                        ? `${activeBar} ${activeText}`
                        : `text-gray-600 dark:text-slate-400 ${hoverBg}`,
                ].join(' ')}
            >
                {anyChildActive && (
                    <span
                        aria-hidden="true"
                        className="sidebar-active-bar absolute inset-y-1.5 left-0 w-0.5 rounded-full bg-[var(--color-primary)]"
                    />
                )}
                <Icon
                    className={[
                        'h-[19px] w-[19px] shrink-0 transition-all duration-200 group-hover:scale-110',
                        anyChildActive
                            ? activeText
                            : 'text-gray-500 group-hover:text-gray-800 dark:text-slate-500 dark:group-hover:text-slate-300',
                    ].join(' ')}
                    aria-hidden="true"
                />
                <span className="min-w-0 flex-1 truncate text-left">{label}</span>
                <ChevronDown
                    className={['sidebar-chevron h-3.5 w-3.5 shrink-0', open ? '' : '-rotate-90'].join(' ')}
                />
            </button>

            <div className="sidebar-collapsible" data-open={open}>
                <div className="sidebar-collapsible-inner">
                    <ul role="list" className="mt-0.5 mb-1 space-y-0.5 pl-5 border-l border-gray-200 ml-5 dark:border-slate-700">
                        {visibleChildren.map((child, i) => (
                            <NavLink key={child.routeName} item={child} collapsed={false} index={i} />
                        ))}
                    </ul>
                </div>
            </div>
        </li>
    );
}

export default function AppSidebar({ onClose, collapsed = false, onToggleCollapse }: Props) {
    const { can } = useCan();
    const { locale, t } = useLocale();
    const { getString } = useSystemSettings();
    const isEmployeeUser = (usePage().props as any).is_employee_user === true;

    const appName        = getString('app.short_name',              'AA Employee ID');
    const orgName        = locale === 'am'
        ? getString('id_cards.city_name_am', getString('general.organization_name', 'አዲስ አበባ ከተማ አስተዳደር'))
        : getString('id_cards.city_name_en', getString('general.organization_name', 'Addis Ababa City Administration'));
    const environmentLabel = getString('general.system_environment_label');
    const sidebarStyle: CSSProperties | undefined = locale === 'am'
        ? { fontFamily: 'var(--font-ethiopic)' }
        : undefined;

    const visibleGroups = useMemo(() =>
        navGroups
            .map((g) => ({
                ...g,
                items: g.items
                    .filter((item) => !item.permission || can(item.permission))
                    .map((item) =>
                        item.children
                            ? { ...item, children: item.children.filter((c) => !c.permission || can(c.permission)) }
                            : item,
                    )
                    .filter((item) => !item.children || item.children.length > 0),
            }))
            .filter((g) => g.items.length > 0),
        [can],
    );

    const visibleAdminGroups = useMemo(() =>
        adminGroups
            .map((g) => ({ ...g, items: g.items.filter((item) => !item.permission || can(item.permission)) }))
            .filter((g) => g.items.length > 0),
        [can],
    );

    const visibleAdminNav = useMemo(() =>
        visibleAdminGroups.flatMap((g) => g.items),
        [visibleAdminGroups],
    );

    const activeGroupKeys = useMemo(() => new Set(
        [...visibleGroups, ...(visibleAdminNav.length > 0 ? [{ key: 'admin', labelKey: 'nav.admin', icon: ShieldCheck, items: visibleAdminNav }] : [])]
            .filter((g) => g.items.some((item) =>
                route().current(item.routeName) ||
                (item.children ?? []).some((c) => route().current(c.routeName)),
            ))
            .map((g) => g.key),
    ), [visibleGroups, visibleAdminNav]);

    const [openGroups, setOpenGroups] = useState<Record<string, boolean>>(() => {
        const defaults: Record<string, boolean> = {};
        for (const g of visibleGroups) {
            defaults[g.key] = g.items.some((item) => route().current(item.routeName));
        }
        if (visibleAdminNav.length > 0) {
            defaults.admin = visibleAdminNav.some((item) => route().current(item.routeName));
        }
        return defaults;
    });

    const toggleGroup = (key: string) =>
        setOpenGroups((cur) => ({ ...cur, [key]: !(cur[key] ?? false) }));

    const renderGroup = (group: NavGroup, isAdmin = false) => {
        const GroupIcon = group.icon;
        const isActive  = activeGroupKeys.has(group.key);
        const isOpen    = openGroups[group.key] ?? isActive;
        const label     = t(group.labelKey);

        if (collapsed) {
            return (
                <ul key={group.key} role="list" className="space-y-0.5 py-0.5">
                    {group.items.map((item) =>
                        item.children
                            ? <NavItemDropdown key={item.routeName} item={item as NavItem & { children: NavSubItem[] }} collapsed />
                            : <NavLink key={item.routeName} item={item} collapsed isAdmin={isAdmin} />
                    )}
                </ul>
            );
        }

        const headerActive  = 'text-[color:var(--color-primary)]';
        const headerDefault = 'text-gray-500 hover:text-gray-900 dark:text-slate-500 dark:hover:text-slate-300';
        const iconActive    = 'text-[color:var(--color-primary)]';

        return (
            <div key={group.key}>
                <button
                    type="button"
                    onClick={() => toggleGroup(group.key)}
                    aria-expanded={isOpen}
                    className={[
                        'sidebar-press flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-left',
                        'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]',
                        isActive ? headerActive : headerDefault,
                    ].join(' ')}
                >
                    <GroupIcon
                        className={['h-4 w-4 shrink-0', isActive ? iconActive : 'text-gray-400 dark:text-slate-600'].join(' ')}
                        aria-hidden="true"
                    />
                    <span className="min-w-0 flex-1 truncate text-sm font-medium">
                        {label}
                    </span>
                    <ChevronDown
                        className={['sidebar-chevron h-3.5 w-3.5 shrink-0', isOpen ? '' : '-rotate-90'].join(' ')}
                    />
                </button>

                <div className="sidebar-collapsible" data-open={isOpen}>
                    <div className="sidebar-collapsible-inner">
                        <ul role="list" className="mt-0.5 mb-1 space-y-0.5 pl-1">
                            {group.items.map((item, i) =>
                                item.children
                                    ? <NavItemDropdown key={item.routeName} item={item as NavItem & { children: NavSubItem[] }} collapsed={false} index={i} />
                                    : <NavLink key={item.routeName} item={item} collapsed={false} isAdmin={isAdmin} index={i} />
                            )}
                        </ul>
                    </div>
                </div>
            </div>
        );
    };

    /** Administration: one consistent collapsible header, with items grouped into labeled sub-clusters. */
    const renderAdminGroup = () => {
        const isActive = activeGroupKeys.has('admin');
        const isOpen   = openGroups.admin ?? isActive;

        const headerActive  = 'text-[color:var(--color-primary)]';
        const headerDefault = 'text-gray-500 hover:text-gray-900 dark:text-slate-500 dark:hover:text-slate-300';
        const iconActive    = 'text-[color:var(--color-primary)]';

        return (
            <div>
                <button
                    type="button"
                    onClick={() => toggleGroup('admin')}
                    aria-expanded={isOpen}
                    className={[
                        'sidebar-press flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-left',
                        'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]',
                        isActive ? headerActive : headerDefault,
                    ].join(' ')}
                >
                    <ShieldCheck
                        className={['h-4 w-4 shrink-0', isActive ? iconActive : 'text-gray-400 dark:text-slate-600'].join(' ')}
                        aria-hidden="true"
                    />
                    <span className="min-w-0 flex-1 truncate text-sm font-medium">{t('nav.admin')}</span>
                    <ChevronDown
                        className={['sidebar-chevron h-3.5 w-3.5 shrink-0', isOpen ? '' : '-rotate-90'].join(' ')}
                    />
                </button>

                <div className="sidebar-collapsible" data-open={isOpen}>
                    <div className="sidebar-collapsible-inner">
                        <div className="mt-0.5 mb-1 space-y-1.5 pl-1">
                            {visibleAdminGroups.map((sub, gi) => {
                                // Continuous stagger index across sub-clusters so items cascade as one list.
                                const offset = visibleAdminGroups.slice(0, gi).reduce((n, g) => n + g.items.length, 0);
                                return (
                                    <div key={sub.labelKey}>
                                        <p className="sidebar-item px-3 py-1 text-[11px] font-medium uppercase tracking-wide text-gray-400 dark:text-slate-600"
                                           style={{ '--i': offset } as CSSProperties}>
                                            {t(sub.labelKey)}
                                        </p>
                                        <ul role="list" className="space-y-0.5">
                                            {sub.items.map((item, i) => (
                                                <NavLink key={item.routeName} item={item} collapsed={false} isAdmin index={offset + i + 1} />
                                            ))}
                                        </ul>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>
            </div>
        );
    };

    return (
        <div
            className="flex h-full w-full flex-col border-r border-gray-200 bg-white text-gray-700 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-200"
            style={sidebarStyle}
            data-sidebar
        >

            {/* ── Header ─────────────────────────────────────────────────────── */}
            {collapsed ? (
                <div className="flex shrink-0 flex-col items-center gap-2 py-3">
                    {onToggleCollapse && (
                        <button
                            type="button"
                            onClick={onToggleCollapse}
                            title="Expand sidebar"
                            aria-label="Expand sidebar"
                            className="flex h-8 w-8 items-center justify-center rounded-lg text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)] dark:text-slate-500 dark:hover:bg-white/8 dark:hover:text-slate-200"
                        >
                            <ChevronRight className="h-3.5 w-3.5" />
                        </button>
                    )}
                    <Link
                        href={isEmployeeUser ? route('employee.portal') : route('dashboard')}
                        title={appName}
                        className="flex h-8 w-8 items-center justify-center focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]"
                    >
                        <ApplicationLogo className="h-full w-full fill-slate-900 object-contain dark:fill-white" />
                    </Link>
                </div>
            ) : (
                <div className="flex h-[60px] shrink-0 items-center gap-2.5 px-4">
                    <Link
                        href={isEmployeeUser ? route('employee.portal') : route('dashboard')}
                        className="flex min-w-0 flex-1 items-center gap-2.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)] rounded-lg"
                    >
                        <div className="flex h-8 w-8 shrink-0 items-center justify-center">
                            <ApplicationLogo className="h-full w-full fill-slate-900 object-contain dark:fill-white" />
                        </div>
                        <div className="min-w-0">
                            <p className="truncate text-[15px] font-medium leading-tight text-gray-950 dark:text-white">
                                {appName}
                            </p>
                            <p className="truncate text-xs leading-tight text-gray-500 dark:text-slate-500">
                                {orgName}
                            </p>
                        </div>
                    </Link>

                    {onClose && (
                        <button
                            type="button"
                            onClick={onClose}
                            aria-label="Close sidebar"
                            className="shrink-0 rounded-md p-1.5 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)] dark:text-slate-500 dark:hover:bg-white/8 dark:hover:text-slate-200"
                        >
                            <X className="h-4 w-4" aria-hidden="true" />
                        </button>
                    )}

                    {onToggleCollapse && (
                        <button
                            type="button"
                            onClick={onToggleCollapse}
                            title="Collapse sidebar"
                            aria-label="Collapse sidebar"
                            className="shrink-0 flex h-7 w-7 items-center justify-center rounded-lg text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)] dark:text-slate-500 dark:hover:bg-white/8 dark:hover:text-slate-200"
                        >
                            <ChevronLeft className="h-3.5 w-3.5" />
                        </button>
                    )}
                </div>
            )}

            {/* ── Navigation ─────────────────────────────────────────────────── */}
            <nav className="sidebar-scroll flex-1 overflow-y-auto py-3" aria-label="Main navigation">

                {/* Dashboard — standalone top item (admin only) */}
                {!isEmployeeUser && (
                    <ul role="list" className={collapsed ? 'space-y-0.5 py-0.5' : 'px-3 pb-2'}>
                        <NavLink item={dashboardNav} collapsed={collapsed} />
                    </ul>
                )}

                {/* Employee portal nav */}
                {isEmployeeUser && (
                    <>
                        <ul role="list" className={collapsed ? 'space-y-0.5 py-0.5' : 'px-3 pb-2 space-y-0.5'}>
                            {employeeNav.map((item) => (
                                <NavLink key={item.routeName} item={item} collapsed={collapsed} />
                            ))}
                        </ul>
                        {!collapsed && <div className="mx-3 mb-2 h-px bg-gray-200 dark:bg-white/5" />}
                    </>
                )}

                {/* Main groups — admin only */}
                {!isEmployeeUser && (
                    <>
                        {!collapsed && <div className="mx-3 mb-2 h-px bg-gray-200 dark:bg-white/5" />}
                        <div className={['space-y-0.5', collapsed ? '' : 'px-3'].join(' ')}>
                            {visibleGroups.map((g) => renderGroup(g, false))}
                        </div>
                    </>
                )}

                {/* Administration — admin only. One consistent collapsible group; items
                    split into labeled sub-clusters (Access Control / System). */}
                {!isEmployeeUser && visibleAdminNav.length > 0 && (
                    collapsed ? (
                        <>
                            <div className="mx-auto my-1 h-px w-8 bg-gray-200 dark:bg-white/5" />
                            <ul role="list" className="space-y-0.5 py-0.5">
                                {visibleAdminNav.map((item) => (
                                    <NavLink key={item.routeName} item={item} collapsed isAdmin />
                                ))}
                            </ul>
                        </>
                    ) : (
                        <>
                            <div className="mx-3 my-2 h-px bg-gray-200 dark:bg-white/5" />
                            <div className="px-3">
                                {renderAdminGroup()}
                            </div>
                        </>
                    )
                )}
            </nav>

            {/* ── Footer ─────────────────────────────────────────────────────── */}
            {!collapsed && (
                <div className="shrink-0 border-t border-gray-200 px-4 py-3 dark:border-white/5">
                    <div className="flex items-center justify-between gap-2">
                        <p className="min-w-0 truncate text-xs text-gray-500 dark:text-slate-500">{orgName}</p>
                        {environmentLabel && (
                            <span className="shrink-0 rounded-full bg-[color:var(--color-accent)]/10 px-2 py-0.5 text-[11px] font-medium text-[color:var(--color-accent)]">
                                {environmentLabel}
                            </span>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
