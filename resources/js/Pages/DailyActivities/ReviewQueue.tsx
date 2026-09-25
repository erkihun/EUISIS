import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ActivityFilters from '@/Components/dailyActivity/ActivityFilters';
import LogTable from '@/Components/dailyActivity/LogTable';
import ManagementNav from '@/Components/dailyActivity/ManagementNav';
import Pager from '@/Components/dailyActivity/Pager';
import { panelCls } from '@/Components/dailyActivity/helpers';
import type { FilterOptions, LogSummary, ManagementAbilities, Paginated } from '@/Components/dailyActivity/types';
import { useLocale } from '@/hooks/useLocale';
import { Head } from '@inertiajs/react';
import type { JSX } from 'react';

type Props = {
    logs: Paginated<LogSummary> | null;
    filters: Record<string, string>;
    hasAssignment: boolean;
    reviewRequired: boolean;
    options: FilterOptions;
    can: ManagementAbilities;
};

/** Oldest first: the longest-waiting day is reviewed first. */
export default function DailyActivitiesReviewQueue({ logs, filters, hasAssignment, reviewRequired, options, can }: Props): JSX.Element {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout header={<PageHeader title={t('dailyActivities.reviewQueue.title')} description={t('dailyActivities.reviewQueue.description')} />}>
            <Head title={t('dailyActivities.reviewQueue.title')} />
            <div className="space-y-3">
                <ManagementNav can={can} current="daily-activities.review-queue" />
                {!reviewRequired && <p className={`${panelCls} p-3 text-sm text-gray-600 dark:text-slate-300`}>{t('dailyActivities.reviewQueue.reviewOff')}</p>}
                {reviewRequired && !hasAssignment && <p className="rounded-panel border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">{t('dailyActivities.reviewQueue.noAssignment')}</p>}
                {logs && (
                    <>
                        <ActivityFilters routeName="daily-activities.review-queue" filters={filters} options={options} fields={['search', 'dateRange', 'unit', 'late']} />
                        <LogTable logs={logs.data} emptyText={t('dailyActivities.reviewQueue.empty')} />
                        <Pager meta={logs.meta} links={logs.links} />
                    </>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
