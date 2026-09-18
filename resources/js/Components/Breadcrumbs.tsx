import { Link, usePage } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { ChevronRight } from '@/Components/Icons';

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
    'recycle-bin': 'nav.recycleBin',
    'create': 'common.create',
    'edit': 'common.edit',
};

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function isIdSegment(segment: string) {
    return /^\d+$/.test(segment) || UUID_RE.test(segment);
}

export default function Breadcrumbs() {
    const { url } = usePage();
    const { t } = useLocale();

    const pathname = url.split('?')[0];
    const rawSegments = pathname.split('/').filter(Boolean);

    // Don't show breadcrumbs on the dashboard itself
    if (rawSegments.length === 0 || rawSegments[0] === 'dashboard') {
        return null;
    }

    type Crumb = { label: string; href: string; isLast: boolean };

    const crumbs: Crumb[] = [{ label: t('nav.dashboard'), href: '/dashboard', isLast: false }];

    let currentPath = '';

    for (let i = 0; i < rawSegments.length; i++) {
        const seg = rawSegments[i];
        currentPath += '/' + seg;

        if (isIdSegment(seg)) {
            // Show "Details" for ID segments only when not followed by 'edit'
            const nextSeg = rawSegments[i + 1];
            if (!nextSeg || !SEGMENT_LABEL_KEYS[nextSeg]) {
                crumbs.push({ label: t('common.details'), href: currentPath, isLast: false });
            }
            continue;
        }

        const labelKey = SEGMENT_LABEL_KEYS[seg];
        if (!labelKey) continue;

        crumbs.push({ label: t(labelKey), href: currentPath, isLast: false });
    }

    // Need at least 2 crumbs to be worth showing
    if (crumbs.length < 2) return null;

    const marked = crumbs.map((c, i) => ({ ...c, isLast: i === crumbs.length - 1 }));

    return (
        <nav
            aria-label="Breadcrumb"
            className="flex items-center gap-1 px-4 py-2 text-xs text-gray-500 bg-gray-50 border-b border-gray-100 dark:bg-slate-900 dark:border-slate-800 dark:text-slate-400 sm:px-6"
        >
            {marked.map((crumb, i) => (
                <span key={i} className="flex items-center gap-1 min-w-0">
                    {i > 0 && (
                        <ChevronRight className="h-3 w-3 shrink-0 text-gray-400 dark:text-slate-600" aria-hidden="true" />
                    )}
                    {crumb.isLast ? (
                        <span className="truncate font-medium text-gray-700 dark:text-slate-200">
                            {crumb.label}
                        </span>
                    ) : (
                        <Link
                            href={crumb.href}
                            className="truncate hover:text-[color:var(--color-primary)] dark:hover:text-blue-400 transition-colors"
                        >
                            {crumb.label}
                        </Link>
                    )}
                </span>
            ))}
        </nav>
    );
}
