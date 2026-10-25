import type { JSX, SVGProps } from 'react';
import {
    BoxesIcon,
    Briefcase,
    Building2,
    CardReceiveIcon,
    CityIcon,
    ClipboardClockIcon,
    ClipboardListIcon,
    CreditCard,
    EyeScanIcon,
    FileSearchIcon,
    GitBranchIcon,
    GitForkIcon,
    IdCardIcon,
    LayoutDashboard,
    QrCodeIcon,
    SettingsIcon,
    ShieldUserIcon,
    TagsIcon,
    UserCircleIcon,
    UserPlusIcon,
    Users,
    UsersTwoIcon,
} from './components/NavIcons';

type Icon = (p: SVGProps<SVGSVGElement>) => JSX.Element;

/** A page: the hash path it opens and its label key. */
export type NavItem = { path: string; labelKey: string; icon: Icon; writer?: boolean; admin?: boolean };
export type NavGroup = { key: string; labelKey: string; icon: Icon; items: NavItem[] };
export type NavSection = { labelKey: string; groups: NavGroup[] };

/** The Core home, as EUISIS's Core workspace names it. */
export const HOME: NavItem = { path: '/', labelKey: 'core.nav.dashboard', icon: LayoutDashboard };

/**
 * The EUISIS Core workspace navigation (App\Services\Core\CoreNavigation),
 * limited to the pages this platform has. Same sections, groups, labels and icons.
 */
export const SECTIONS: NavSection[] = [
    {
        labelKey: 'core.nav.sectionRecords',
        groups: [
            {
                key: 'coreOrganization', labelKey: 'nav.groupOrganization', icon: CityIcon,
                items: [
                    { path: '/organizations', labelKey: 'nav.organizations', icon: Building2 },
                    { path: '/organization-types', labelKey: 'nav.organizationTypes', icon: TagsIcon },
                    { path: '/organization-units', labelKey: 'nav.organizationUnits', icon: GitBranchIcon },
                    { path: '/unit-types', labelKey: 'nav.organizationUnitTypes', icon: BoxesIcon },
                    { path: '/hierarchy', labelKey: 'nav.hierarchy', icon: GitForkIcon },
                ],
            },
            {
                key: 'coreWorkforce', labelKey: 'core.nav.groupWorkforce', icon: Briefcase,
                items: [
                    { path: '/positions', labelKey: 'nav.positions', icon: Briefcase },
                ],
            },
            {
                key: 'coreEmployees', labelKey: 'core.nav.groupEmployees', icon: UsersTwoIcon,
                items: [
                    { path: '/employees', labelKey: 'nav.employees', icon: Users },
                    { path: '/assignments', labelKey: 'core.nav.assignments', icon: ClipboardListIcon },
                    { path: '/employees/new', labelKey: 'nav.registerEmployee', icon: UserPlusIcon, writer: true },
                ],
            },
            {
                key: 'coreCredentials', labelKey: 'core.nav.groupIdentity', icon: IdCardIcon,
                items: [
                    { path: '/id-cards', labelKey: 'nav.idCards', icon: CreditCard },
                    { path: '/card-requests', labelKey: 'nav.cardRequests', icon: CardReceiveIcon },
                    { path: '/verify', labelKey: 'core.nav.verification', icon: QrCodeIcon },
                ],
            },
        ],
    },
    {
        labelKey: 'core.nav.sectionGovernance',
        groups: [
            {
                key: 'coreAccess', labelKey: 'nav.adminAccess', icon: ShieldUserIcon,
                items: [
                    { path: '/users', labelKey: 'nav.users', icon: UserCircleIcon, admin: true },
                ],
            },
            {
                key: 'coreControl', labelKey: 'core.nav.groupControl', icon: EyeScanIcon,
                items: [
                    { path: '/audit', labelKey: 'nav.auditLogs', icon: ClipboardClockIcon },
                    { path: '/data-quality', labelKey: 'nav.dataQuality', icon: FileSearchIcon, admin: true },
                ],
            },
            {
                key: 'coreSettings', labelKey: 'core.nav.settings', icon: SettingsIcon,
                items: [
                    { path: '/settings', labelKey: 'core.nav.settings', icon: SettingsIcon, admin: true },
                ],
            },
        ],
    },
];

/** The sections an account may see: register is for officers and admins, settings for admins. */
export function visibleSections(canWrite: boolean, isAdmin = false): NavSection[] {
    return SECTIONS
        .map((section) => ({
            ...section,
            groups: section.groups
                .map((group) => ({ ...group, items: group.items.filter((item) => (!item.writer || canWrite) && (!item.admin || isAdmin)) }))
                .filter((group) => group.items.length > 0),
        }))
        .filter((section) => section.groups.length > 0);
}

/** The page a route belongs to (an exact path, else the longest prefix: /employees/abc → Employees). */
export function activeItem(route: string, items: NavItem[]): NavItem | undefined {
    const exact = items.find((item) => item.path === route);
    if (exact) return exact;
    return items
        .filter((item) => item.path !== '/' && route.startsWith(item.path + '/'))
        .sort((a, b) => b.path.length - a.path.length)[0];
}

/** Where a route sits in the tree, for breadcrumbs: its group and page. */
export function navLocation(route: string): { group: NavGroup; item: NavItem } | null {
    const groups = SECTIONS.flatMap((section) => section.groups);
    const item = activeItem(route, groups.flatMap((group) => group.items));
    if (!item) return null;
    const group = groups.find((candidate) => candidate.items.includes(item));
    return group ? { group, item } : null;
}
