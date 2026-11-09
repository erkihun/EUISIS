import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pager from '@/Components/dailyActivity/Pager';
import { Head } from '@inertiajs/react';
import type { JSX } from 'react';
import { useLocale } from '@/hooks/useLocale';
import Filters from './Filters';
import ManagementNav from './ManagementNav';
import RequestTable from './RequestTable';
import type { FieldWorkSummary, FilterOptions, ManagementAbilities, Paginated } from './types';

export type ListPageProps = {
    requests: Paginated<FieldWorkSummary>;
    filters: Record<string, string>;
    options: FilterOptions;
    routeName: string;
    can: ManagementAbilities;
};

/** One frame for the four Field Work Management list pages. */
export default function ListPage({ titleKey, descriptionKey, emptyKey, showStatus = true, ...props }: ListPageProps & {
    titleKey: string;
    descriptionKey: string;
    emptyKey: string;
    showStatus?: boolean;
}): JSX.Element {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout header={<PageHeader title={t(titleKey)} description={t(descriptionKey)} />}>
            <Head title={t(titleKey)} />
            <div className="space-y-3">
                <ManagementNav can={props.can} current={props.routeName} />
                <Filters routeName={props.routeName} filters={props.filters} options={props.options} showStatus={showStatus} />
                <RequestTable rows={props.requests.data} emptyText={t(emptyKey)} href={(row) => route('field-work.requests.show', row.id)} />
                <Pager meta={props.requests.meta} links={props.requests.links} />
            </div>
        </AuthenticatedLayout>
    );
}
