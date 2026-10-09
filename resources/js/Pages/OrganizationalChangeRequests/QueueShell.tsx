import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ChangeRequestTable from '@/Components/organizationalChange/ChangeRequestTable';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { Plus } from '@/Components/Icons';
import type { JSX } from 'react';
import type {
    ChangeRequestSummary,
    ChangeRequestListMeta,
    FilterOptions,
    QueueAbilities,
} from '@/Components/organizationalChange/types';

export type QueueProps = {
    requests: { data: ChangeRequestSummary[]; meta: ChangeRequestListMeta };
    filters: Record<string, string>;
    options: FilterOptions;
    can: QueueAbilities;
    queue: string;
};

type Props = QueueProps & {
    titleKey: string;
    emptyKey: string;
    routeName: string;
    showAssignee?: boolean;
    showCreate?: boolean;
};

const inputCls =
    'rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

/**
 * Shared shell for the four queues.
 *
 * They differ only in which server-side scope produced the rows, so the
 * filters, table and pagination live here once rather than four times.
 */
export default function QueueShell({
    requests,
    filters,
    options,
    can,
    titleKey,
    emptyKey,
    routeName,
    showAssignee = false,
    showCreate = false,
}: Props): JSX.Element {
    const { t, locale } = useLocale();
    const am = locale === 'am';

    function applyFilter(key: string, value: string) {
        router.get(route(routeName), { ...filters, [key]: value || undefined }, { preserveState: true, preserveScroll: true });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t(`organizationalChangeRequests.queues.${titleKey}`)}
                    description={t('organizationalChangeRequests.description')}
                    actions={
                        showCreate && can.create ? (
                            <Link
                                href={route('organizational-change-requests.create')}
                                className="inline-flex items-center gap-2 rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-semibold text-white hover:bg-[color:var(--color-primary-hover)]"
                            >
                                <Plus className="h-4 w-4" aria-hidden="true" />
                                {t('organizationalChangeRequests.actions.create')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={t(`organizationalChangeRequests.queues.${titleKey}`)} />

            <div className="space-y-4">
                <section className="flex flex-wrap items-center gap-2 rounded-panel border border-gray-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
                    <input
                        type="search"
                        className={`${inputCls} min-w-56 flex-1`}
                        placeholder={t('organizationalChangeRequests.filters.search')}
                        defaultValue={filters.search ?? ''}
                        onKeyDown={event => {
                            if (event.key === 'Enter') {
                                applyFilter('search', (event.target as HTMLInputElement).value);
                            }
                        }}
                    />
                    <select className={inputCls} value={filters.status ?? ''} onChange={event => applyFilter('status', event.target.value)}>
                        <option value="">{t('organizationalChangeRequests.filters.allStatuses')}</option>
                        {options.statuses.map(status => (
                            <option key={status} value={status}>
                                {t(`organizationalChangeRequests.statuses.${status}`)}
                            </option>
                        ))}
                    </select>
                    <select className={inputCls} value={filters.request_type ?? ''} onChange={event => applyFilter('request_type', event.target.value)}>
                        <option value="">{t('organizationalChangeRequests.filters.allTypes')}</option>
                        {options.requestTypes.map(type => (
                            <option key={type} value={type}>
                                {t(`organizationalChangeRequests.types.${type}`)}
                            </option>
                        ))}
                    </select>
                    <select className={inputCls} value={filters.organization_id ?? ''} onChange={event => applyFilter('organization_id', event.target.value)}>
                        <option value="">{t('organizationalChangeRequests.filters.allOrganizations')}</option>
                        {options.organizations.map(organization => (
                            <option key={organization.id} value={organization.id}>
                                {am ? organization.name_am || organization.name_en : organization.name_en}
                            </option>
                        ))}
                    </select>
                </section>

                <ChangeRequestTable
                    requests={requests.data}
                    meta={requests.meta}
                    emptyMessage={t(`organizationalChangeRequests.empty.${emptyKey}`)}
                    routeName={routeName}
                    filters={filters}
                    showAssignee={showAssignee}
                />
            </div>
        </AuthenticatedLayout>
    );
}
