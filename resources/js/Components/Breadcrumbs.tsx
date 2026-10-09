import { Link, usePage } from '@inertiajs/react';
import { navLocation } from '@/Components/AppSidebar';
import { ChevronRight } from '@/Components/Icons';
import { useCan } from '@/hooks/useCan';
import { useLocale } from '@/hooks/useLocale';

/** Fallback labels for pages outside the navigation tree, keyed by URL segment. */
const SEGMENT_LABEL_KEYS: Record<string, string> = {
    'dashboard': 'nav.dashboard',
    'organizations': 'nav.organizations',
    'organization-types': 'nav.organizationTypes',
    'organization-units': 'nav.organizationUnits',
    'organization-unit-types': 'nav.organizationUnitTypes',
    'hierarchy-versions': 'nav.hierarchyVersions',
    'code-rules': 'nav.codeRules',
    'employees': 'nav.employees',
    'employee-transfers': 'nav.employeeTransfers',
    'transfers': 'nav.transferManagement',
    'transfer-announcements': 'transfers.announcements',
    'transfer-applications': 'transfers.applications',
    'positions': 'nav.positions',
    'id-cards': 'nav.idCards',
    'card-requests': 'nav.cardRequests',
    'print-batches': 'nav.printBatches',
    'service-types': 'nav.serviceTypes',
    'service-providers': 'nav.providers',
    'entitlements': 'nav.entitlements',
    'entitlement-rules': 'nav.entitlementRules',
    'audit-logs': 'nav.auditLogs',
    'users': 'nav.users',
    'roles': 'nav.roles',
    'permissions': 'nav.permissions',
    'system-settings': 'nav.systemSettings',
    'api-management': 'nav.apiManagement',
    'endpoints': 'apiManagement.apiEndpoints',
    'logs': 'apiManagement.apiLogs',
    'docs': 'apiManagement.apiDocumentation',
    'recycle-bin': 'nav.recycleBin',
    'create': 'common.create',
    'edit': 'common.edit',
};

/** Route-name suffixes that describe an action on the module's record. */
const ACTION_LABEL_KEYS: Record<string, string> = { create: 'common.create', edit: 'common.edit', show: 'common.details' };

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

type Crumb = { label: string; href?: string };

function isIdSegment(segment: string) {
    return /^\d+$/.test(segment) || UUID_RE.test(segment);
}

/** Breadcrumb trail: from the navigation tree when the page is in it, else from the URL. */
export default function Breadcrumbs({ className = '' }: { className?: string }) {
    const { url, props } = usePage();
    const { t } = useLocale();
    const { can } = useCan();
    const [pathname, query = ''] = url.split('?');
    const employeePortal = props.is_employee_user === true;
    const current = String(route().current() ?? '');
    const lookup = (key: string, fallback: string) => (t(key) === key ? fallback : t(key));
    const humanize = (segment: string) => segment.replace(/[-_]/g, ' ').replace(/^\w/, (letter) => letter.toUpperCase());

    let crumbs: Crumb[];
    const location = navLocation(current, employeePortal, new URLSearchParams(query).get('tab'));
    if (location) {
        const { groupLabelKey, item } = location;
        const permitted = (!item.permission || can(item.permission)) && (!item.anyPermission || item.anyPermission.some((permission) => can(permission)));
        const isCurrent = current === item.routeName;
        crumbs = [
            { label: t(employeePortal ? 'nav.myPortal' : 'nav.dashboard'), href: route(employeePortal ? 'employee.portal' : 'dashboard') },
            // Groups organise the menu; they are not pages, so they are never links.
            { label: t(groupLabelKey) },
            { label: t(item.labelKey), href: isCurrent || !permitted ? undefined : route(item.routeName) + (item.tab ? '?tab=' + item.tab : '') },
        ];
        if (!isCurrent) {
            // A sub-page of the module: name it by its action (create/edit/show) or by its own route segment.
            const segment = current.split('.').filter((part) => part !== 'index').pop() ?? '';
            const key = ACTION_LABEL_KEYS[segment] ?? SEGMENT_LABEL_KEYS[segment] ?? 'common.' + segment.replace(/-(\w)/g, (_, letter: string) => letter.toUpperCase());
            crumbs.push({ label: lookup(key, humanize(segment)) });
        }
    } else {
        const segments = pathname.split('/').filter(Boolean);
        if (segments.length === 0 || segments[0] === 'dashboard') return null;
        crumbs = [{ label: t('nav.dashboard'), href: '/dashboard' }];
        let path = '';
        segments.forEach((segment, index) => {
            path += '/' + segment;
            if (isIdSegment(segment)) {
                // "Details" for a record, unless an action such as edit follows it.
                if (!SEGMENT_LABEL_KEYS[segments[index + 1]]) crumbs.push({ label: t('common.details'), href: path });
            } else if (SEGMENT_LABEL_KEYS[segment]) {
                crumbs.push({ label: t(SEGMENT_LABEL_KEYS[segment]), href: path });
            }
        });
    }
    if (crumbs.length < 2) return null;

    return (
        <nav aria-label="Breadcrumb" className={'min-w-0 overflow-x-auto text-xs text-[color:var(--app-muted-foreground)] ' + className}>
            <ol className="flex min-w-max items-center gap-1.5 whitespace-nowrap">
                {crumbs.map((crumb, index) => {
                    const last = index === crumbs.length - 1;
                    return (
                        <li key={index} className="flex min-w-0 items-center gap-1.5">
                            {index > 0 && <ChevronRight className="h-3 w-3 shrink-0 opacity-60" aria-hidden="true" />}
                            {last ? (
                                <span aria-current="page" className="max-w-64 truncate font-medium text-[color:var(--app-foreground)]">{crumb.label}</span>
                            ) : crumb.href ? (
                                <Link href={crumb.href} className="max-w-48 truncate rounded transition-colors hover:text-[color:var(--color-primary)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)]">
                                    {crumb.label}
                                </Link>
                            ) : (
                                <span className="max-w-48 truncate">{crumb.label}</span>
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
