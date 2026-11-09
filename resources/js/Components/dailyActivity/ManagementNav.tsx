import { Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { ManagementAbilities } from './types';

/**
 * One line of text links between the management pages. Each entry appears
 * only when the server said the user may open it; the server re-checks.
 */
export default function ManagementNav({ can, current }: { can: ManagementAbilities; current: string }) {
    const { t } = useLocale();

    const entries = [
        { route: 'daily-activities.dashboard', label: 'nav.dailyActivityDashboard', show: can.viewReports || can.viewRegister },
        { route: 'daily-activities.index', label: 'nav.dailyActivityRegister', show: can.viewRegister },
        { route: 'daily-activities.missing', label: 'nav.dailyActivityMissing', show: can.viewReports || can.viewRegister },
        { route: 'daily-activities.review-queue', label: 'nav.dailyActivityReviewQueue', show: can.review },
        { route: 'daily-activities.reports', label: 'nav.dailyActivityReports', show: can.viewReports },
        { route: 'daily-activities.reviewers.index', label: 'nav.dailyActivityReviewers', show: can.manageReviewers },
        { route: 'daily-activities.settings', label: 'nav.dailyActivitySettings', show: can.manageSettings },
    ].filter((entry) => entry.show);

    return (
        <nav className="-mx-1 flex gap-1 overflow-x-auto border-b border-(--app-border) pb-px text-sm" aria-label={t('nav.dailyActivities')}>
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
                                ? 'border-primary font-semibold text-primary'
                                : 'border-transparent text-(--app-muted-foreground) hover:text-(--app-foreground)',
                        ].join(' ')}
                    >
                        {t(entry.label)}
                    </Link>
                );
            })}
        </nav>
    );
}
