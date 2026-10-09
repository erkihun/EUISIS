import { Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { ManagementAbilities } from './types';

/**
 * Tab row between the Field Work Management pages, mirroring the sidebar
 * group. Entries come from server abilities; every page re-checks.
 */
export default function ManagementNav({ can, current }: { can: ManagementAbilities; current: string }) {
    const { t } = useLocale();

    const entries = [
        { route: 'field-work.dashboard', label: 'nav.fieldWorkDashboard', show: can.dashboard },
        { route: 'field-work.requests.index', label: 'nav.fieldWorkRequests', show: can.requests },
        { route: 'field-work.pending', label: 'nav.fieldWorkApprovals', show: can.approvals },
        { route: 'field-work.team.index', label: 'nav.fieldWorkTeam', show: can.team },
        { route: 'field-work.availability.index', label: 'nav.fieldWorkAvailability', show: can.availability },
        { route: 'field-work.overdue.index', label: 'nav.fieldWorkOverdue', show: can.overdue },
        { route: 'field-work.types.index', label: 'nav.fieldWorkTypes', show: can.types },
    ].filter((entry) => entry.show);

    return (
        <nav className="-mx-1 flex gap-1 overflow-x-auto border-b border-gray-200 pb-px text-sm dark:border-slate-800" aria-label={t('nav.fieldWorkManagement')}>
            {entries.map((entry) => {
                const active = entry.route === current;
                return (
                    <Link
                        key={entry.route}
                        href={route(entry.route)}
                        aria-current={active ? 'page' : undefined}
                        className={[
                            'whitespace-nowrap border-b-2 px-2.5 py-2',
                            active
                                ? 'border-[color:var(--color-primary)] font-semibold text-[color:var(--color-primary)]'
                                : 'border-transparent text-gray-600 hover:text-gray-900 dark:text-slate-400 dark:hover:text-slate-100',
                        ].join(' ')}
                    >
                        {t(entry.label)}
                    </Link>
                );
            })}
        </nav>
    );
}
