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
    NfcIcon,
    RouterIcon,
    SearchIcon,
    ChevronRight,
    ChevronDown,
} from '@/Components/Icons';
import { type CSSProperties, type SVGProps, useEffect, useId, useRef, useState } from 'react';
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
            { routeName: 'organizations.index', labelKey: 'nav.organizations', icon: Building2,     permission: 'organizations.view' },
            { routeName: 'organization-types.index', labelKey: 'nav.organizationTypes', icon: TagsIcon,      permission: 'organization-types.viewAny' },
            { routeName: 'organization-units.index', labelKey: 'nav.organizationUnits', icon: GitBranchIcon, permission: 'organization-units.viewAny' },
            { routeName: 'organization-unit-types.index', labelKey: 'nav.organizationUnitTypes', icon: BoxesIcon, permission: 'organization-unit-types.viewAny' },
            { routeName: 'hierarchy-versions.index', labelKey: 'nav.hierarchyVersions', icon: GitForkIcon,   permission: 'hierarchy-versions.viewAny' },
            /*
             * The controller also accepts `functional-reporting.viewReports`;
             * only one permission can gate a nav entry, so the broader of the
             * two is used and the page re-checks both on arrival.
             */
            { routeName: 'reporting-lines.index', labelKey: 'nav.reportingLines', icon: NetworkIcon,   permission: 'relationships.viewAny' },
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
            { routeName: 'positions.index', labelKey: 'nav.positions', icon: Briefcase,          permission: 'positions.viewAny' },
            { routeName: 'positions.status', labelKey: 'nav.newJobPositionsStatus', icon: ClipboardCheckIcon, permission: 'positions.viewAny' },
            { routeName: 'position-establishments.index', labelKey: 'nav.positionEstablishments', icon: ClipboardListIcon, permission: 'position-establishments.viewAny' },
            { routeName: 'position-services.index', labelKey: 'nav.positionServices', icon: Layers,             permission: 'service_feedback.settings.manage' },
            { routeName: 'grade-levels.index', labelKey: 'nav.gradeLevels', icon: TrendingUpIcon,     permission: 'grade-levels.viewAny' },
            { routeName: 'occupations.index', labelKey: 'nav.occupations', icon: HardHatIcon,        permission: 'occupations.viewAny' },
            { routeName: 'isic-activities.index', labelKey: 'nav.isicActivities', icon: ActivityIcon,       permission: 'isic-activities.viewAny' },
        ],
    },
    {
        key: 'employeeManagement',
        labelKey: 'nav.groupEmployeeManagement',
        icon: Users,
        items: [
            { routeName: 'employees.index', labelKey: 'nav.employees', icon: Users,              permission: 'employees.view' },
            { routeName: 'employees.import.create', labelKey: 'nav.employeeImport', icon: ClipboardListIcon,  permission: 'employees.import.view' },
            { routeName: 'vacancy-announcements.index', labelKey: 'nav.vacancyAnnouncements', icon: MegaphoneIcon,      permission: 'vacancy-announcements.viewAny' },
            { routeName: 'vacancy-applications.my-applications', labelKey: 'nav.myApplications',        icon: Inbox },
            { routeName: 'transfers.dashboard', labelKey: 'nav.transferDashboard', icon: ArrowLeftRightIcon, permission: 'transfers.view' },
            { routeName: 'transfer-announcements.index', labelKey: 'nav.transferAnnouncements', icon: MegaphoneIcon, permission: 'transfers.announcements.view' },
            { routeName: 'transfer-applications.index', labelKey: 'nav.transferApplications', icon: Inbox,              permission: 'transfers.applications.view' },
            { routeName: 'transfer-settings.show', labelKey: 'nav.transferSettings', icon: SettingsIcon,       permission: 'transfers.settings.manage' },
        ],
    },
    {
        key: 'identity',
        labelKey: 'nav.groupIdentity',
        icon: CreditCard,
        items: [
            { routeName: 'id-cards.index', labelKey: 'nav.idCards', icon: CreditCard,         permission: 'cards.view' },
            { routeName: 'card-requests.index', labelKey: 'nav.cardRequests', icon: ClipboardCheckIcon, permission: 'card-requests.viewAny' },
            { routeName: 'nfc-management.dashboard', labelKey: 'nav.nfcManagement', icon: NfcIcon,       permission: 'nfc_credentials.view' },
            { routeName: 'nfc-management.terminals.index', labelKey: 'nav.nfcTerminals', icon: RouterIcon,     permission: 'nfc_terminals.view' },
            { routeName: 'nfc-management.logs.index', labelKey: 'nav.nfcVerificationLogs', icon: ScrollText,     permission: 'nfc_logs.view' },
        ],
    },
    {
        key: 'grievances',
        labelKey: 'nav.groupGrievances',
        icon: ScrollText,
        items: [
            { routeName: 'grievances.index', labelKey: 'nav.grievances', icon: ClipboardListIcon, permission: 'grievances.manage' },
            { routeName: 'grievances.my', labelKey: 'nav.myGrievances',        icon: Inbox },
            { routeName: 'grievance-committees.index', labelKey: 'nav.grievanceCommittees', icon: Users, permission: 'grievances.manage' },
            { routeName: 'grievance-categories.index', labelKey: 'nav.grievanceCategories', icon: TagsIcon, permission: 'grievances.manage' },
            { routeName: 'grievance-sla-rules.index', labelKey: 'nav.grievanceSlaRules', icon: SettingsIcon,      permission: 'grievances.manage' },
            { routeName: 'tribunal-cases.index', labelKey: 'nav.tribunalCases', icon: ShieldCheck,       permission: 'grievances.tribunal' },
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
            { routeName: 'service-feedback.admin.dashboard', labelKey: 'nav.serviceFeedbackDashboard', icon: LayoutDashboard, permission: 'service_feedback.view' },
            { routeName: 'service-feedback.admin.index', labelKey: 'nav.serviceFeedbackList', icon: MessageSquareIcon, permission: 'service_feedback.view' },
            { routeName: 'service-feedback.admin.reports', labelKey: 'nav.serviceFeedbackReports', icon: StarIcon,          permission: 'service_feedback.view' },
            { routeName: 'service-types.index', labelKey: 'nav.serviceTypes', icon: Layers,            permission: 'service-types.viewAny' },
            { routeName: 'entitlements.index', labelKey: 'nav.entitlements',             icon: BadgeCheckIcon },
            { routeName: 'entitlement-rules.index', labelKey: 'nav.entitlementRules', icon: ReceiptTextIcon,   permission: 'entitlement-rules.viewAny' },
        ],
    },
    {
        key: 'cafeteria',
        labelKey: 'nav.groupCafeteria',
        icon: QrCodeIcon,
        items: [
            { routeName: 'cafeteria.dashboard', labelKey: 'nav.cafeteriaDashboard', icon: LayoutDashboard, permission: 'cafeteria_transactions.viewAny' },
            { routeName: 'cafeteria.scan', labelKey: 'nav.cafeteriaScan', icon: QrCodeIcon,      permission: 'cafeteria_transactions.scan' },
            { routeName: 'cafeteria.transactions.index', labelKey: 'nav.cafeteriaTransactions', icon: ReceiptTextIcon, permission: 'cafeteria_transactions.viewAny' },
            { routeName: 'cafeteria.ledger.index', labelKey: 'nav.cafeteriaLedger', icon: ScrollText,      permission: 'cafeteria_ledger.viewAny' },
            { routeName: 'cafeteria.reports.index', labelKey: 'nav.cafeteriaReports', icon: ActivityIcon,    permission: 'cafeteria_reports.viewAny' },
            { routeName: 'cafeteria.providers.index', labelKey: 'nav.cafeteriaProviders', icon: HandshakeIcon,   permission: 'cafeteria_providers.viewAny' },
            { routeName: 'cafeteria.settings.index', labelKey: 'nav.cafeteriaSettings', icon: SettingsIcon,    permission: 'cafeteria_settings.view' },
        ],
    },
    {
        key: 'transport',
        labelKey: 'nav.groupTransport',
        icon: ActivityIcon,
        items: [
            { routeName: 'transport.providers.index', labelKey: 'nav.transportProviders', icon: HandshakeIcon, permission: 'transport-providers.viewAny' },
            { routeName: 'transport.scan', labelKey: 'nav.transportScan', icon: QrCodeIcon,      permission: 'transport-passes.viewAny' },
            { routeName: 'transport.routes.index', labelKey: 'nav.transportRoutes', icon: ScrollText,      permission: 'transport-routes.viewAny' },
            { routeName: 'transport.vehicles.index', labelKey: 'nav.transportVehicles', icon: ActivityIcon,    permission: 'transport-vehicles.viewAny' },
            { routeName: 'transport.drivers.index', labelKey: 'nav.transportDrivers', icon: UserIcon,        permission: 'transport-drivers.viewAny' },
            { routeName: 'transport.passes.index', labelKey: 'nav.transportPasses', icon: BadgeCheckIcon,  permission: 'transport-passes.viewAny' },
            { routeName: 'transport.reports.index', labelKey: 'nav.transportReports', icon: ReceiptTextIcon, permission: 'transport-reports.view' },
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
            { routeName: 'service-providers.index', labelKey: 'nav.providers',              icon: HandshakeIcon },
            { routeName: 'api-management.index', labelKey: 'nav.apiManagement', icon: NetworkIcon, permission: 'api_management.view' },
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
    { routeName: 'employee.portal', labelKey: 'nav.myPortal',             icon: UserIcon },
    { routeName: 'employee.entitlements', labelKey: 'nav.myEntitlements',       icon: BadgeCheckIcon },
    { routeName: 'employee.transfer-applications', labelKey: 'nav.transferApplications', icon: Inbox },
    { routeName: 'public.transfer-announcements', labelKey: 'nav.announcements',         icon: MegaphoneIcon },
];

/** Administration is split into labeled sub-clusters so unrelated concerns stay scannable. */
const adminGroups: { labelKey: string; items: NavItem[] }[] = [
    {
        labelKey: 'nav.adminAccess',
        items: [
            { routeName: 'users.index', labelKey: 'nav.users', icon: UserCogIcon,   permission: 'users.viewAny' },
            { routeName: 'provider-users.index', labelKey: 'nav.cafeteriaProviderUsers', icon: HandshakeIcon, permission: 'cafeteria-provider-users.viewAny' },
            { routeName: 'roles.index', labelKey: 'nav.roles', icon: ShieldCheck,   permission: 'roles.viewAny' },
            { routeName: 'permissions.index', labelKey: 'nav.permissions', icon: KeyIcon,       permission: 'permissions.viewAny' },
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
        ],
    },
];

const SIDEBAR_GROUPS_STORAGE_KEY = 'euisis-sidebar-open-groups';

const sections = [
    { labelKey: 'nav.sidebarPeople', keys: ['employeeManagement', 'organization', 'hrMasterData', 'identity'] },
    { labelKey: 'nav.sidebarOperations', keys: ['serviceManagement', 'cafeteria', 'transport', 'grievances'] },
    { labelKey: 'nav.sidebarGovernance', keys: ['providers', 'auditMonitoring'] },
];

interface Props {
    onClose?: () => void;
    collapsed?: boolean;
    onToggleCollapse?: () => void;
}

const focusRing = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[color:var(--sidebar-accent)]';
const hoverSurface = 'hover:bg-[color:var(--sidebar-hover)]';
const selectedSurface = 'bg-[color:var(--sidebar-accent-wash)] text-[color:var(--sidebar-accent)]';

/** Exact routes win; otherwise select the nearest permitted module index. */
function activeRoute(items: NavSubItem[], current: string): string | undefined {
    if (items.some((item) => item.routeName === current)) return current;
    return items
        .filter((item) => /\.(index|dashboard)$/.test(item.routeName))
        .map((item) => ({ name: item.routeName, prefix: item.routeName.replace(/\.(index|dashboard)$/, '') }))
        .filter((item) => current.startsWith(item.prefix + '.'))
        .sort((a, b) => b.prefix.length - a.prefix.length)[0]?.name;
}

export default function AppSidebar({ onClose, collapsed = false, onToggleCollapse }: Props) {
    const { can } = useCan();
    const { locale, t } = useLocale();
    const { getString } = useSystemSettings();
    const { props: pageProps, url: pageUrl } = usePage();
    const isEmployeeUser = pageProps.is_employee_user === true;
    const instanceId = useId();
    const searchRef = useRef<HTMLInputElement>(null);
    const focusSearchAfterExpand = useRef(false);
    const [query, setQuery] = useState('');
    const normalizedQuery = query.trim().toLocaleLowerCase();

    const appName = getString('app.short_name', 'AA Employee ID');
    const orgName = locale === 'am'
        ? getString('id_cards.city_name_am', getString('general.organization_name', 'አዲስ አበባ ከተማ አስተዳደር'))
        : getString('id_cards.city_name_en', getString('general.organization_name', 'Addis Ababa City Administration'));
    const environmentLabel = getString('general.system_environment_label');
    const logoCentered = getString('appearance.logo_position', 'start') === 'center';
    const sidebarStyle: CSSProperties | undefined = locale === 'am'
        ? { fontFamily: 'var(--font-ethiopic)' }
        : undefined;

    // Keep the existing permission contract, including independently permitted child links.
    const allowedItems = (items: NavItem[]): NavSubItem[] => items
        .filter((item) => !item.permission || can(item.permission))
        .flatMap((item) => item.children
            ? item.children.filter((child) => !child.permission || can(child.permission))
            : [item]);
    const visibleGroups = navGroups.map((group) => ({ ...group, items: allowedItems(group.items) }))
        .filter((group) => group.items.length > 0);
    const visibleAdminGroups = adminGroups.map((group) => ({ ...group, items: allowedItems(group.items) }))
        .filter((group) => group.items.length > 0);
    const visibleAdminNav = visibleAdminGroups.flatMap((group) => group.items);
    const adminGroup: NavGroup = { key: 'admin', labelKey: 'nav.admin', icon: ShieldCheck, items: visibleAdminNav };
    const allGroups = [...visibleGroups, ...(visibleAdminNav.length ? [adminGroup] : [])];
    const currentRoute = String(route().current() ?? '');
    const currentItem = activeRoute(
        isEmployeeUser ? employeeNav : [dashboardNav, ...allGroups.flatMap((group) => group.items)],
        currentRoute,
    );
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

    function renderLink(item: NavSubItem, nested = false) {
        const selected = currentItem === item.routeName && (!item.tab
            || new URLSearchParams(pageUrl.split('?')[1] ?? '').get('tab') === item.tab);
        const Icon = item.icon;
        const label = t(item.labelKey);
        return (
            <li key={item.routeName + (item.tab ?? '')}>
                <Link
                    href={item.tab ? route(item.routeName) + '?tab=' + item.tab : route(item.routeName)}
                    onClick={onClose}
                    aria-current={selected ? 'page' : undefined}
                    aria-label={collapsed ? label : undefined}
                    title={collapsed ? label : undefined}
                    className={[
                        'relative flex min-h-10 items-center gap-2.5 rounded-lg text-sm transition-colors',
                        focusRing, hoverSurface,
                        collapsed ? 'mx-auto h-11 w-11 justify-center' : 'px-3 py-2.5',
                        selected ? selectedSurface + ' font-semibold' : 'font-medium text-[color:var(--sidebar-fg)]',
                    ].join(' ')}
                >
                    {selected && <span aria-hidden="true" className="absolute inset-y-2 start-0 w-0.5 rounded-full bg-[color:var(--sidebar-accent)]" />}
                    {(!nested || collapsed) && <Icon aria-hidden="true" className="h-[18px] w-[18px] shrink-0" />}
                    {!collapsed && <span className="min-w-0 flex-1 whitespace-normal break-words leading-relaxed">{label}</span>}
                    {!collapsed && selected && <span aria-hidden="true" className="h-1.5 w-1.5 shrink-0 rounded-full bg-current" />}
                </Link>
            </li>
        );
    }

    function renderGroup(group: NavGroup) {
        const items = matchingItems(group);
        if (!items.length) return null;
        const Icon = group.icon;
        const selected = activeGroupKeys.includes(group.key);
        const isOpen = Boolean(normalizedQuery || openGroups[group.key]);
        const panelId = instanceId + '-group-' + group.key;
        const label = t(group.labelKey);
        if (group.items.length === 1 && group.key !== 'admin') {
            return <ul key={group.key}>{renderLink(group.items[0])}</ul>;
        }
        return (
            <div key={group.key}>
                <button
                    type="button"
                    title={collapsed ? label : undefined}
                    aria-label={collapsed ? label : undefined}
                    aria-expanded={collapsed ? false : isOpen}
                    aria-controls={panelId}
                    onClick={() => {
                        if (collapsed) {
                            setOpenGroups((previous) => ({ ...previous, [group.key]: true }));
                            onToggleCollapse?.();
                        } else if (!normalizedQuery) {
                            setOpenGroups((previous) => ({ ...previous, [group.key]: !isOpen }));
                        }
                    }}
                    className={[
                        'flex min-h-11 w-full items-center gap-2.5 rounded-lg text-left text-sm transition-colors',
                        focusRing, hoverSurface,
                        collapsed ? 'mx-auto !w-11 justify-center' : 'px-3 py-2.5',
                        selected ? selectedSurface + ' font-semibold' : 'font-medium text-[color:var(--sidebar-fg)]',
                    ].join(' ')}
                >
                    <Icon className="h-[18px] w-[18px] shrink-0" aria-hidden="true" />
                    {!collapsed && <>
                        <span className="min-w-0 flex-1 whitespace-normal break-words leading-relaxed">{label}</span>
                        <ChevronDown className={['h-3.5 w-3.5 shrink-0 text-[color:var(--sidebar-muted)]', isOpen ? '' : '-rotate-90'].join(' ')} aria-hidden="true" />
                    </>}
                </button>
                <div id={panelId} hidden={collapsed || !isOpen}>
                    <div className="ms-5 my-1 border-s border-[color:var(--sidebar-border)] ps-3">
                        {group.key === 'admin' ? visibleAdminGroups.map((subgroup) => {
                            const subItems = subgroup.items.filter((item) => items.some((match) => match.routeName === item.routeName));
                            return subItems.length > 0 && (
                                <div key={subgroup.labelKey} className="py-1">
                                    <p className="px-3 py-2 text-xs font-semibold text-[color:var(--sidebar-muted)]">{t(subgroup.labelKey)}</p>
                                    <ul className="space-y-0.5">{subItems.map((item) => renderLink(item, true))}</ul>
                                </div>
                            );
                        }) : <ul className="space-y-0.5">{items.map((item) => renderLink(item, true))}</ul>}
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div data-sidebar className="flex h-full min-h-0 w-full flex-col border-e border-[color:var(--sidebar-border)] bg-[color:var(--sidebar-bg)] text-[color:var(--sidebar-fg)]" style={sidebarStyle}>
            <div className={collapsed ? 'flex min-h-20 shrink-0 items-center justify-center border-b border-[color:var(--sidebar-border)]' : 'shrink-0 border-b border-[color:var(--sidebar-border)] p-4'}>
                <div className="flex items-start gap-2">
                    <Link
                        href={isEmployeeUser ? route('employee.portal') : route('dashboard')}
                        onClick={onClose}
                        aria-label={appName}
                        title={collapsed ? appName : undefined}
                        className={['flex min-w-0 flex-1 gap-3 rounded-lg', focusRing, logoCentered && !collapsed ? 'flex-col items-center text-center' : 'items-center'].join(' ')}
                    >
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center">
                            <ApplicationLogo className="h-full w-full object-contain" />
                        </span>
                        {!collapsed && <div className="min-w-0">
                            <p className="whitespace-normal break-words text-[15px] font-medium leading-snug">{appName}</p>
                            <p className="mt-1 whitespace-normal break-words text-xs leading-relaxed text-[color:var(--sidebar-muted)]">{orgName}</p>
                        </div>}
                    </Link>
                    {onClose && <button type="button" onClick={onClose} aria-label={t('nav.closeMenu')} className={['flex h-10 w-10 shrink-0 items-center justify-center rounded-lg', focusRing, hoverSurface].join(' ')}><X className="h-4 w-4" aria-hidden="true" /></button>}
                </div>
            </div>

            <div className={collapsed ? 'px-2 pt-3' : 'px-3 pt-3'}>
                {collapsed ? <button
                    type="button"
                    aria-label={t('nav.searchNavigation')}
                    title={t('nav.searchNavigation')}
                    onClick={() => { focusSearchAfterExpand.current = true; onToggleCollapse?.(); }}
                    className={['mx-auto flex h-11 w-11 items-center justify-center rounded-lg text-[color:var(--sidebar-muted)]', focusRing, hoverSurface].join(' ')}
                ><SearchIcon className="h-[18px] w-[18px]" aria-hidden="true" /></button> : (
                    <div className="flex items-center gap-2 rounded-lg border border-[color:var(--sidebar-border)] bg-[color:var(--sidebar-hover)] px-3 focus-within:ring-2 focus-within:ring-[color:var(--sidebar-accent)]">
                        <SearchIcon className="h-4 w-4 shrink-0 text-[color:var(--sidebar-muted)]" aria-hidden="true" />
                        <input
                            ref={searchRef}
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            onKeyDown={(event) => { if (event.key === 'Escape' && query) { event.preventDefault(); event.stopPropagation(); setQuery(''); } }}
                            aria-label={t('nav.searchNavigation')}
                            placeholder={t('nav.searchNavigation')}
                            className="h-10 w-full min-w-0 border-0 bg-transparent p-0 text-sm text-[color:var(--sidebar-fg)] placeholder:text-[color:var(--sidebar-muted)] focus:ring-0"
                        />
                    </div>
                )}
            </div>

            <nav className="sidebar-scroll min-h-0 flex-1 overflow-y-auto overscroll-contain px-2 py-3" aria-label={t('publicSite.mainNavigation')}>
                {isEmployeeUser ? (
                    <ul className="space-y-1">{employeeNav.filter(matches).map((item) => renderLink(item))}</ul>
                ) : <>
                    {(!normalizedQuery || matches(dashboardNav)) && <ul className="mb-3">{renderLink(dashboardNav)}</ul>}
                    {sections.map((section) => {
                        const groups = section.keys.flatMap((key) => visibleGroups.filter((group) => group.key === key));
                        if (section.labelKey === 'nav.sidebarGovernance' && visibleAdminNav.length) groups.push(adminGroup);
                        if (!groups.some((group) => matchingItems(group).length)) return null;
                        return <div key={section.labelKey} className="mb-4 last:mb-0">
                            {collapsed
                                ? <div className="mx-3 my-2 border-t border-[color:var(--sidebar-border)]" />
                                : <p className="px-3 pb-2 pt-1 text-[11px] font-semibold leading-relaxed tracking-wide text-[color:var(--sidebar-muted)]">{t(section.labelKey)}</p>}
                            <div className="space-y-1">{groups.map(renderGroup)}</div>
                        </div>;
                    })}
                </>}
                {normalizedQuery && (isEmployeeUser ? !employeeNav.some(matches) : !matchingGroups.length && !matches(dashboardNav)) && (
                    <p role="status" className="px-3 py-5 text-sm leading-relaxed text-[color:var(--sidebar-muted)]">{t('nav.noResults')}</p>
                )}
            </nav>

            <div className="shrink-0 border-t border-[color:var(--sidebar-border)] p-2">
                {onToggleCollapse ? (
                    <button type="button" onClick={onToggleCollapse} aria-label={t(collapsed ? 'nav.expandSidebar' : 'nav.collapseSidebar')} title={collapsed ? t('nav.expandSidebar') : undefined}
                        className={['flex min-h-11 items-center gap-3 rounded-lg text-sm text-[color:var(--sidebar-muted)]', focusRing, hoverSurface, collapsed ? 'mx-auto w-11 justify-center' : 'w-full px-3'].join(' ')}>
                        <ChevronRight className={collapsed ? 'h-4 w-4' : 'h-4 w-4 rotate-180'} aria-hidden="true" />
                        {!collapsed && <span>{t('nav.collapseSidebar')}</span>}
                    </button>
                ) : <p className="px-3 py-2 text-xs text-[color:var(--sidebar-muted)]">{t(isEmployeeUser ? 'nav.myPortal' : 'nav.admin')}</p>}
                {!collapsed && environmentLabel && environmentLabel.toLowerCase() !== 'production' && <p className="px-3 pb-1 text-xs text-[color:var(--sidebar-muted)]">{environmentLabel}</p>}
            </div>
        </div>
    );
}
