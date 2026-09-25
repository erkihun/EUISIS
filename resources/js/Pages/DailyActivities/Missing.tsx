import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import ActivityFilters from '@/Components/dailyActivity/ActivityFilters';
import ManagementNav from '@/Components/dailyActivity/ManagementNav';
import Pager from '@/Components/dailyActivity/Pager';
import { DayStatusBadge } from '@/Components/dailyActivity/StatusBadges';
import { panelCls } from '@/Components/dailyActivity/helpers';
import type { FilterOptions, ManagementAbilities, Paginated } from '@/Components/dailyActivity/types';
import { useLocale } from '@/hooks/useLocale';
import { Head } from '@inertiajs/react';
import type { JSX } from 'react';

type Row = {
    date: string;
    employee_number: string;
    employee: string;
    organization_unit: string | null;
    position: string | null;
    day_status: string;
};

type Props = {
    rows: Paginated<Row>;
    filters: Record<string, string>;
    options: FilterOptions;
    can: ManagementAbilities;
};

/**
 * Required working days with nothing submitted. Calculated, not stored: the
 * server derives each row from employment, assignment, the work calendar,
 * public holidays and leave.
 */
export default function DailyActivitiesMissing({ rows, filters, options, can }: Props): JSX.Element {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout header={<PageHeader title={t('dailyActivities.missing.title')} description={t('dailyActivities.missing.description')} />}>
            <Head title={t('dailyActivities.missing.title')} />
            <div className="space-y-3">
                <ManagementNav can={can} current="daily-activities.missing" />
                <ActivityFilters routeName="daily-activities.missing" filters={filters} options={options} fields={['search', 'dateRange', 'organization', 'unit', 'position']} />

                {rows.data.length === 0 ? (
                    <p className={`${panelCls} p-4 text-sm text-gray-500 dark:text-slate-400`}>{t('dailyActivities.missing.empty')}</p>
                ) : (
                    <ul className={`${panelCls} divide-y divide-gray-100 dark:divide-slate-800`}>
                        {rows.data.map((row) => (
                            <li key={`${row.employee_number}-${row.date}`} className="grid gap-1 px-3 py-2 text-sm sm:grid-cols-[8rem_1fr_1fr_auto] sm:items-center sm:gap-3">
                                <LocalizedDateDisplay value={row.date} className="font-medium text-gray-900 dark:text-slate-100" />
                                <span className="min-w-0 text-gray-800 dark:text-slate-200">
                                    {row.employee} <span className="text-xs text-gray-500 dark:text-slate-400">{row.employee_number}</span>
                                </span>
                                <span className="min-w-0 truncate text-xs text-gray-600 dark:text-slate-400">
                                    {[row.organization_unit, row.position].filter(Boolean).join(' · ')}
                                </span>
                                <span>
                                    {row.day_status === 'draft'
                                        ? <span className="text-xs text-gray-600 dark:text-slate-400">{t('dailyActivities.missing.staleDraft')}</span>
                                        : <DayStatusBadge status="missing" />}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
                <Pager meta={rows.meta} links={rows.links} />
            </div>
        </AuthenticatedLayout>
    );
}
