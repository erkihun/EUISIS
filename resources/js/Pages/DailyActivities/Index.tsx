import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ActivityFilters from '@/Components/dailyActivity/ActivityFilters';
import LogTable from '@/Components/dailyActivity/LogTable';
import ManagementNav from '@/Components/dailyActivity/ManagementNav';
import Pager from '@/Components/dailyActivity/Pager';
import type { FilterOptions, LogSummary, ManagementAbilities, Paginated } from '@/Components/dailyActivity/types';
import { useLocale } from '@/hooks/useLocale';
import { Head } from '@inertiajs/react';
import type { JSX } from 'react';

type Props = {
    logs: Paginated<LogSummary>;
    filters: Record<string, string>;
    options: FilterOptions;
    can: ManagementAbilities;
};

/** Daily Activity Register: every log inside the viewer's scope. */
export default function DailyActivitiesIndex({ logs, filters, options, can }: Props): JSX.Element {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout header={<PageHeader title={t('dailyActivities.register.title')} description={t('dailyActivities.register.description')} />}>
            <Head title={t('dailyActivities.register.title')} />
            <div className="space-y-3">
                <ManagementNav can={can} current="daily-activities.index" />
                <ActivityFilters
                    routeName="daily-activities.index"
                    filters={filters}
                    options={options}
                    fields={['search', 'dateRange', 'organization', 'unit', 'position', 'status', 'late', 'service']}
                />
                <LogTable logs={logs.data} emptyText={t('dailyActivities.register.empty')} />
                <Pager meta={logs.meta} links={logs.links} />
            </div>
        </AuthenticatedLayout>
    );
}
