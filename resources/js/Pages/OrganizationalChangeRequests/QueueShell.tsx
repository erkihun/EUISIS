import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ChangeRequestTable from '@/Components/organizationalChange/ChangeRequestTable';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { Plus } from '@/Components/Icons';
import { useState, type JSX } from 'react';
import type {
    ChangeRequestSummary,
    ChangeRequestListMeta,
    FilterOptions,
    QueueAbilities,
} from '@/Components/organizationalChange/types';

import { FilterField, FilterPanel, Input, Select, buttonClassName } from '@/Components/ui';
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

    const [draft, setDraft] = useState({ search: filters.search ?? '', status: filters.status ?? '', request_type: filters.request_type ?? '', organization_id: filters.organization_id ?? '' });

    function applyFilters() {
        router.get(route(routeName), Object.fromEntries(Object.entries(draft).filter(([, value]) => value)), { preserveState: true, preserveScroll: true });
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
                                className={buttonClassName({ variant: 'primary' })}
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
                <FilterPanel onSubmit={applyFilters} onReset={() => router.get(route(routeName))} hasActiveFilters={Boolean(filters.search || filters.status || filters.request_type || filters.organization_id)}>
                    <FilterField label={t('common.search')} wide>
                        <Input type="search" placeholder={t('organizationalChangeRequests.filters.search')} value={draft.search} onChange={event => setDraft({ ...draft, search: event.target.value })} />
                    </FilterField>
                    <FilterField label={t('common.status')}>
                        <Select value={draft.status} onChange={event => setDraft({ ...draft, status: event.target.value })}>
                            <option value="">{t('organizationalChangeRequests.filters.allStatuses')}</option>
                            {options.statuses.map(status => (
                                <option key={status} value={status}>
                                    {t(`organizationalChangeRequests.statuses.${status}`)}
                                </option>
                            ))}
                        </Select>
                    </FilterField>
                    <FilterField label={t('common.type')}>
                        <Select value={draft.request_type} onChange={event => setDraft({ ...draft, request_type: event.target.value })}>
                            <option value="">{t('organizationalChangeRequests.filters.allTypes')}</option>
                            {options.requestTypes.map(type => (
                                <option key={type} value={type}>
                                    {t(`organizationalChangeRequests.types.${type}`)}
                                </option>
                            ))}
                        </Select>
                    </FilterField>
                    <FilterField label={t('common.organization')}>
                        <Select value={draft.organization_id} onChange={event => setDraft({ ...draft, organization_id: event.target.value })}>
                            <option value="">{t('organizationalChangeRequests.filters.allOrganizations')}</option>
                            {options.organizations.map(organization => (
                                <option key={organization.id} value={organization.id}>
                                    {am ? organization.name_am || organization.name_en : organization.name_en}
                                </option>
                            ))}
                        </Select>
                    </FilterField>
                </FilterPanel>

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
